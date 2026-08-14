-- Genera una base del tamaño que tendría TRAZA con 5 tiendas en marcha.
--
-- 5 tiendas es el escenario que `docs/14` §5 señala como el punto a partir
-- del cual el sistema tiene sentido económico, y `docs/05` §2.3 le asigna
-- ~2,4 M lecturas al mes. Con los 90 días de retención en caliente eso son
-- ~7,2 M filas en `tag_reads`, que es el volumen contra el que hay que medir
-- el RTO de la tarea 8.6: cronometrar una restauración de mil tags no dice
-- nada, tarda un segundo siempre.
--
-- No pasa por `StockMovementService` a propósito: aquí no se está probando la
-- lógica de dominio —eso ya lo hacen las 502 pruebas— sino el tamaño físico
-- de la base. Insertar 7 M de lecturas por Eloquent tardaría horas.
--
-- Uso:  psql -d traza_prod_scale -f database/perf/escala-produccion.sql

\timing on
SET synchronous_commit = off;

-- ------------------------------------------------------------ estructura

INSERT INTO organizations (name, tax_id, epc_filter_mask)
VALUES ('VivaTech Escala', '20100000001', '3035D9');

INSERT INTO locations (organization_id, code, name, kind)
SELECT 1, 'LIM-' || lpad(n::text, 2, '0'), 'Gamarra ' || n, 'tienda'
FROM generate_series(1, 5) n;

INSERT INTO zones (location_id, code, name, kind)
SELECT l.id, z.code, z.name, z.kind::zone_kind
FROM locations l
CROSS JOIN (VALUES
    ('SALA',  'Sala de ventas', 'sala'),
    ('ALM',   'Trastienda',     'trastienda'),
    ('PROB',  'Probadores',     'probador')
) AS z(code, name, kind);

INSERT INTO devices (organization_id, location_id, code, name, kind,
                     regulatory_region, status)
SELECT 1, l.id, 'EDGE-' || l.code, 'Borde ' || l.name, 'edge', 'FCC-PE', 'activo'
FROM locations l;

-- 400 variantes: un surtido de confección realista para cinco locales.
INSERT INTO products (organization_id, code, name, brand)
SELECT 1, 'P-' || lpad(n::text, 4, '0'),
       (ARRAY['Polo','Camisa','Jean','Casaca','Vestido'])[1 + n % 5] || ' ' || n,
       'VivaTech'
FROM generate_series(1, 400) n;

INSERT INTO product_variants (product_id, sku, item_reference, color, size,
                              cost_price, sale_price)
SELECT p.id,
       'SKU-' || lpad(p.id::text, 4, '0') || '-' || s.size,
       p.id,
       (ARRAY['Azul','Negro','Blanco','Rojo'])[1 + p.id % 4],
       s.size,
       25 + (p.id % 40),
       59 + (p.id % 90)
FROM products p
CROSS JOIN (VALUES ('S'), ('M'), ('L'), ('XL')) AS s(size);

-- ---------------------------------------------------------------- prendas
--
-- 100 000 = 5 tiendas × 20 000 prendas, el escenario de `docs/05` §2.3.
-- El EPC va en mayúsculas: el check `tags_epc_hex` las exige.

INSERT INTO tags (organization_id, epc, tid, product_variant_id, state,
                  current_location_id, current_zone_id, commissioned_at,
                  last_seen_at)
SELECT 1,
       '3035D9' || upper(lpad(to_hex(n), 18, '0')),
       upper(lpad(to_hex(n * 7919), 24, '0')),
       1 + (hashint4(n) & 2147483647) % 1600,
       (ARRAY['en_stock','en_stock','en_stock','en_stock',
              'vendido','vendido','en_transito'])[1 + n % 7]::tag_state,
       1 + (n % 5),
       (n % 5) * 3 + 1 + (n % 3),
       now() - ((hashint4(n * 31) & 2147483647) % 300 || ' days')::interval,
       now() - (n % 20 || ' days')::interval
FROM generate_series(1, 100000) n;


-- ------------------------------------------------------------ movimientos
--
-- `stock_as_of()` no suma entradas y salidas: coge el **último** movimiento de
-- cada prenda y mira su `state_after` y su `to_location_id`. Por eso el libro
-- se construye para que ese último movimiento coincida con la proyección de
-- `tags`, que es justo lo que compara el control de integridad de `docs/05`
-- §6. Un fixture que suspende el control del propio proyecto no vale: el
-- siguiente que lo ejecute pensará que el sistema está roto.

-- Tarado: entra en stock. Una por prenda.
INSERT INTO stock_movements (organization_id, tag_id, product_variant_id,
                             movement_type, to_location_id, to_zone_id,
                             state_before, state_after, unit_cost, occurred_at)
SELECT 1, t.id, t.product_variant_id, 'tarado',
       t.current_location_id, t.current_zone_id,
       'codificado', 'en_stock', 30, t.commissioned_at
FROM tags t;

-- Y la salida de las que ya no están en stock, que es la que manda por ser la
-- última. `to_location_id` a NULL: la prenda dejó la tienda.
--
-- La fecha tiene que caer **entre el tarado y ahora**, y las dos mitades
-- importan. Si cae después de ahora, `stock_as_of(loc, now())` no la ve y la
-- prenda sigue contando como stock. Si se recorta con un `LEAST(..., now())`
-- a secas, la de una prenda tarada hoy queda *antes* de su propio tarado y
-- entonces el último movimiento vuelve a ser el tarado. De ahí la mitad del
-- intervalo transcurrido: siempre posterior al tarado y siempre pasada.
INSERT INTO stock_movements (organization_id, tag_id, product_variant_id,
                             movement_type, from_location_id, from_zone_id,
                             state_before, state_after, unit_cost, occurred_at)
SELECT 1, t.id, t.product_variant_id,
       CASE t.state
           WHEN 'vendido'     THEN 'venta'
           WHEN 'en_transito' THEN 'transferencia_out'
       END::movement_type,
       t.current_location_id, t.current_zone_id,
       'en_stock', t.state, 30,
       t.commissioned_at + LEAST(interval '5 days', (now() - t.commissioned_at) / 2)
FROM tags t
WHERE t.state IN ('vendido', 'en_transito');

-- Y solo ahora se les quita la ubicación actual: los movimientos de arriba
-- la necesitaban para registrarse contra la tienda correcta. Sin este paso el
-- control de integridad las contaría como stock proyectado que el libro
-- desmiente.
UPDATE tags SET current_location_id = NULL, current_zone_id = NULL
WHERE state IN ('vendido', 'en_transito');

-- ---------------------------------------------------------------- lecturas
--
-- ~7,2 M repartidas por los 90 días de retención, para que caigan en las tres
-- particiones mensuales y no todas en la del mes en curso. Se insertan por
-- tandas de 30 días: una sola sentencia de 7 M filas hace que el WAL crezca
-- más de lo que hace falta.

INSERT INTO tag_reads (device_id, location_id, epc,
                       antenna_port, rssi, read_at, read_count)
SELECT 1 + (t.id % 5),
       1 + (t.id % 5),
       t.epc,
       1 + (t.id % 4),
       -45 - (t.id % 30),
       now() - (random() * 29)::int * interval '1 day' - (random() * 86400)::int * interval '1 second',
       1
FROM tags t
CROSS JOIN generate_series(1, 24) r;

INSERT INTO tag_reads (device_id, location_id, epc,
                       antenna_port, rssi, read_at, read_count)
SELECT 1 + (t.id % 5),
       1 + (t.id % 5),
       t.epc,
       1 + (t.id % 4),
       -45 - (t.id % 30),
       now() - interval '30 days' - (random() * 29)::int * interval '1 day',
       1
FROM tags t
CROSS JOIN generate_series(1, 24) r;

INSERT INTO tag_reads (device_id, location_id, epc,
                       antenna_port, rssi, read_at, read_count)
SELECT 1 + (t.id % 5),
       1 + (t.id % 5),
       t.epc,
       1 + (t.id % 4),
       -45 - (t.id % 30),
       now() - interval '60 days' - (random() * 29)::int * interval '1 day',
       1
FROM tags t
CROSS JOIN generate_series(1, 24) r;

ANALYZE;
