-- =============================================================================
--  TRAZA — Datos de referencia y semilla de desarrollo
--
--  Uso:
--    psql -d traza -f sql/schema.sql
--    psql -d traza -f sql/vistas-analiticas.sql
--    psql -d traza -f sql/seeds.sql
--
--  ⚠️ NO EJECUTAR EN PRODUCCIÓN. Los EPC generados usan el prefijo de pruebas.
-- =============================================================================

BEGIN;

-- -----------------------------------------------------------------------------
-- 1. Organización
-- -----------------------------------------------------------------------------
INSERT INTO organizations (name, tax_id, gs1_company_prefix, default_epc_scheme,
                           epc_filter_mask, timezone, settings)
VALUES (
    'Comercial Ejemplo S.A.C.',
    '20123456789',
    '7751234',
    'sgtin-96',
    '3035D9',
    'America/Lima',
    '{"moneda": "PEN", "igv": 0.18}'::jsonb
);

-- -----------------------------------------------------------------------------
-- 2. Ubicaciones y zonas
-- -----------------------------------------------------------------------------
INSERT INTO locations (organization_id, code, name, kind, address, is_active)
VALUES
    (1, 'LIM-01', 'Tienda Gamarra 1',  'tienda',  'Jr. Gamarra 123, La Victoria, Lima', TRUE),
    (1, 'LIM-02', 'Tienda Gamarra 2',  'tienda',  'Jr. Antonio Bazo 456, La Victoria',  TRUE),
    (1, 'CEN-01', 'Almacén central',   'almacen', 'Av. Aviación 1200, Ate, Lima',       TRUE),
    (1, 'TRA-01', 'En tránsito',       'transito', NULL,                                TRUE);

-- Zonas de la tienda 1
INSERT INTO zones (location_id, code, name, kind, counts_as_sellable, sort_order)
VALUES
    (1, 'ESC',  'Escaparate',       'escaparate', TRUE,  1),
    (1, 'SALA', 'Sala principal',   'sala',       TRUE,  2),
    (1, 'PROB', 'Probadores',       'probador',   TRUE,  3),
    (1, 'CAJA', 'Punto de caja',    'caja',       TRUE,  4),
    (1, 'TRA-A','Trastienda A',     'trastienda', FALSE, 5),
    (1, 'TRA-B','Trastienda B',     'trastienda', FALSE, 6),
    (1, 'SAL',  'Salida',           'salida',     FALSE, 7);

-- Zonas de la tienda 2
INSERT INTO zones (location_id, code, name, kind, counts_as_sellable, sort_order)
VALUES
    (2, 'SALA', 'Sala principal',   'sala',       TRUE,  1),
    (2, 'PROB', 'Probadores',       'probador',   TRUE,  2),
    (2, 'TRA',  'Trastienda',       'trastienda', FALSE, 3),
    (2, 'SAL',  'Salida',           'salida',     FALSE, 4);

-- Zonas del almacén
INSERT INTO zones (location_id, code, name, kind, counts_as_sellable, sort_order)
VALUES
    (3, 'REC',  'Recepción',        'recepcion',  FALSE, 1),
    (3, 'STK',  'Estantería stock', 'trastienda', FALSE, 2),
    (3, 'ETQ',  'Etiquetado',       'otro',       FALSE, 3);

-- -----------------------------------------------------------------------------
-- 3. Roles
-- -----------------------------------------------------------------------------
INSERT INTO roles (code, name, permissions) VALUES
    ('vendedor', 'Vendedor', '[
        "stock.view","tags.view","tags.locate","sales.create","sales.return","alerts.view"
     ]'::jsonb),
    ('almacen', 'Personal de almacén', '[
        "stock.view","tags.view","tags.locate","tags.commission","tags.replace",
        "receiving.manage","transfers.manage","labels.print","alerts.view"
     ]'::jsonb),
    ('jefe_tienda', 'Jefe de tienda', '[
        "stock.view","stock.adjust","tags.*","cycles.manage","cycles.close",
        "receiving.manage","transfers.manage","labels.print","alerts.manage","reports.location"
     ]'::jsonb),
    ('supervisor_regional', 'Supervisor regional', '[
        "stock.*","tags.*","cycles.*","receiving.*","transfers.*",
        "alerts.*","reports.*","adjustments.approve"
     ]'::jsonb),
    ('gerencia', 'Gerencia', '[
        "stock.view","reports.*","analytics.*"
     ]'::jsonb),
    -- El técnico configura dispositivos pero NO puede ajustar stock.
    -- Separación deliberada: ver docs/12, §2.
    ('tecnico', 'Responsable técnico', '[
        "devices.*","read_profiles.*","diagnostics.*","stock.view","tags.view"
     ]'::jsonb),
    ('admin', 'Administrador', '["*"]'::jsonb);

-- -----------------------------------------------------------------------------
-- 4. Usuarios de desarrollo
--    Contraseña de todos: "password"  (hash bcrypt de Laravel)
-- -----------------------------------------------------------------------------
INSERT INTO users (organization_id, name, email, password, default_location_id, is_active)
VALUES
    (1, 'Admin Sistema',   'admin@ejemplo.pe',
        '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, TRUE),
    (1, 'María Quispe',    'maria@ejemplo.pe',
        '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, TRUE),
    (1, 'José Flores',     'jose@ejemplo.pe',
        '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 3, TRUE),
    (1, 'Carla Mendoza',   'carla@ejemplo.pe',
        '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, TRUE);

INSERT INTO role_user (user_id, role_id) VALUES
    (1, 7),   -- admin
    (2, 1),   -- María: vendedora
    (3, 2),   -- José: almacén
    (4, 3);   -- Carla: jefa de tienda

-- -----------------------------------------------------------------------------
-- 5. Perfiles de lectura
--    Estos valores son el punto de partida; se ajustan en calibración.
--    Ver docs/01, §2.2 para la justificación de cada sesión.
-- -----------------------------------------------------------------------------
INSERT INTO read_profiles (organization_id, code, name, session, target, initial_q,
                           tx_power_dbm, dedup_window_ms, min_read_count,
                           rssi_threshold, read_tid, notes)
VALUES
    (1, 'INV-SALA', 'Inventario en sala', 2, 'A', 6, 28.0, 2000, 1, -70.0, FALSE,
        'S2 evita releer lo ya contado durante el barrido.'),
    (1, 'INV-ALM',  'Inventario en almacén', 2, 'A', 7, 30.0, 2000, 1, -72.0, FALSE,
        'Población más densa: Q más alto y potencia máxima.'),
    (1, 'TARADO',   'Tarado individual', 0, 'A', 2, 16.0, 500, 3, -45.0, TRUE,
        'POTENCIA BAJA A PROPÓSITO: solo debe leer la prenda en la mano. '
        'Lee TID para el testigo antifraude. Ver docs/09, §4.'),
    (1, 'PORTAL',   'Portal de salida', 0, 'A', 4, 26.0, 200, 2, -62.0, FALSE,
        'S0 para que el tag reporte repetidamente mientras cruza.'),
    (1, 'TUNEL',    'Túnel de recepción', 1, 'A', 6, 27.0, 1000, 2, -65.0, TRUE,
        'S1 con Q ajustado al tamaño del bulto.'),
    (1, 'CAJA',     'Antena de campo cercano en caja', 0, 'A', 3, 12.0, 300, 2, -40.0, FALSE,
        'Umbral de RSSI muy alto: solo lo que está sobre el mostrador.'),
    (1, 'BUSQUEDA', 'Modo Geiger', 0, 'A', 2, 20.0, 100, 1, NULL, FALSE,
        'Sin umbral: la proximidad se infiere del RSSI crudo.');

-- -----------------------------------------------------------------------------
-- 6. Dispositivos
-- -----------------------------------------------------------------------------
INSERT INTO devices (organization_id, location_id, code, name, kind, manufacturer,
                     model, ip_address, regulatory_region, status, settings)
VALUES
    (1, 1, 'EDGE-LIM01', 'Borde tienda 1',       'edge',        'Intel',    'NUC N100',
        '192.168.10.10', 'FCC-PE', 'activo', '{}'::jsonb),
    (1, 1, 'HH-LIM01-01','Handheld tienda 1 #1', 'handheld',    'Zebra',    'RFD40+TC22',
        NULL,            'FCC-PE', 'activo', '{"profile_default":"INV-SALA"}'::jsonb),
    (1, 1, 'HH-LIM01-02','Handheld tienda 1 #2', 'handheld',    'Chainway', 'C72',
        NULL,            'FCC-PE', 'activo', '{"profile_default":"INV-SALA"}'::jsonb),
    (1, 1, 'PORT-LIM01', 'Portal salida tienda 1','lector_fijo','Zebra',    'FX9600',
        '192.168.10.21', 'FCC-PE', 'activo', '{"profile":"PORTAL"}'::jsonb),
    (1, 3, 'TUN-CEN01',  'Túnel de recepción',   'lector_fijo', 'Chainway', 'UR4',
        '192.168.20.22', 'FCC-PE', 'activo', '{"profile":"TUNEL"}'::jsonb),
    (1, 3, 'PRN-CEN01',  'Impresora RFID',       'impresora',   'Zebra',    'ZT411 RFID',
        '192.168.20.31', 'FCC-PE', 'activo', '{"dpi":203,"label_mm":"50x30"}'::jsonb);

-- Antenas del portal: la geometría importa para clasificar dirección (docs/07, §4)
INSERT INTO device_antennas (device_id, zone_id, port_number, label, mount_height_cm,
                             tilt_degrees, side, tx_power_dbm, rssi_threshold, is_enabled)
VALUES
    (4, 7, 1, 'Portal izq. alta',  160, 12, 'interior', 26.0, -62.0, TRUE),
    (4, 7, 2, 'Portal izq. baja',   60, 12, 'interior', 26.0, -62.0, TRUE),
    (4, 7, 3, 'Portal der. alta',  160, 12, 'exterior', 26.0, -62.0, TRUE),
    (4, 7, 4, 'Portal der. baja',   60, 12, 'exterior', 26.0, -62.0, TRUE);

-- Antenas del túnel
INSERT INTO device_antennas (device_id, zone_id, port_number, label, tx_power_dbm,
                             rssi_threshold, is_enabled)
VALUES
    (5, 12, 1, 'Túnel superior',  27.0, -65.0, TRUE),
    (5, 12, 2, 'Túnel inferior',  27.0, -65.0, TRUE),
    (5, 12, 3, 'Túnel lateral A', 27.0, -65.0, TRUE),
    (5, 12, 4, 'Túnel lateral B', 27.0, -65.0, TRUE);

-- -----------------------------------------------------------------------------
-- 7. Catálogo
-- -----------------------------------------------------------------------------
INSERT INTO suppliers (organization_id, code, name, tax_id, is_active) VALUES
    (1, 'PRV-001', 'Textiles Andinos S.A.C.',  '20111222333', TRUE),
    (1, 'PRV-002', 'Confecciones Gamarra EIRL','20444555666', TRUE),
    (1, 'PRV-003', 'Denim Import S.A.',        '20777888999', TRUE);

INSERT INTO categories (organization_id, parent_id, code, name, path) VALUES
    (1, NULL, 'MUJ',      'Mujer',            '/mujer'),
    (1, NULL, 'HOM',      'Hombre',           '/hombre'),
    (1, 1,    'MUJ-SUP',  'Superiores mujer', '/mujer/superiores'),
    (1, 1,    'MUJ-INF',  'Inferiores mujer', '/mujer/inferiores'),
    (1, 2,    'HOM-SUP',  'Superiores hombre','/hombre/superiores'),
    (1, 2,    'HOM-INF',  'Inferiores hombre','/hombre/inferiores');

INSERT INTO seasons (organization_id, code, name, starts_on, ends_on) VALUES
    (1, 'V26', 'Verano 2026',   '2025-11-01', '2026-03-31'),
    (1, 'I26', 'Invierno 2026', '2026-04-01', '2026-09-30');

-- rfid_difficulty: 1 = fácil (algodón), 5 = difícil (metálico, mojado).
-- Se rellena tras la prueba PC01 (docs/15, §8).
INSERT INTO products (organization_id, category_id, season_id, supplier_id, code, name,
                      brand, composition, rfid_difficulty, is_active)
VALUES
    (1, 3, 2, 1, 'POL-OVER',  'Polera Oversize',      'Ejemplo', '100% algodón',            1, TRUE),
    (1, 3, 2, 2, 'BLU-LINO',  'Blusa de Lino',        'Ejemplo', '55% lino, 45% viscosa',   1, TRUE),
    (1, 4, 2, 3, 'JEA-SLIM',  'Jean Slim',            'Ejemplo', '98% algodón, 2% elastano',3, TRUE),
    (1, 5, 2, 1, 'CAM-BASIC', 'Camiseta Básica',      'Ejemplo', '100% algodón',            1, TRUE),
    (1, 6, 2, 3, 'JEA-RECTO', 'Jean Recto Hombre',    'Ejemplo', '100% algodón',            3, TRUE),
    (1, 3, 2, 2, 'TOP-LENT',  'Top con Lentejuelas',  'Ejemplo', 'poliéster con aplicación metálica', 5, TRUE);

-- Variantes. item_reference = 6 dígitos (partición 5, docs/04, §2.1)
INSERT INTO product_variants (product_id, sku, size, color, color_hex, barcode, gtin13,
                              item_reference, cost_price, sale_price, currency, min_stock)
VALUES
    (1, 'POL-OVER-M-NEG', 'M',  'Negro', '#111111', '7751234123456', '7751234123456', '012345', 32.00,  89.90, 'PEN', 2),
    (1, 'POL-OVER-L-NEG', 'L',  'Negro', '#111111', '7751234123463', '7751234123463', '012346', 32.00,  89.90, 'PEN', 2),
    (1, 'POL-OVER-M-BLA', 'M',  'Blanco','#FFFFFF', '7751234123470', '7751234123470', '012347', 32.00,  89.90, 'PEN', 2),
    (2, 'BLU-LINO-S-BEI', 'S',  'Beige', '#D8C9A9', '7751234123487', '7751234123487', '012348', 41.00, 119.90, 'PEN', 1),
    (2, 'BLU-LINO-M-BEI', 'M',  'Beige', '#D8C9A9', '7751234123494', '7751234123494', '012349', 41.00, 119.90, 'PEN', 1),
    (3, 'JEA-SLIM-28-AZU','28', 'Azul',  '#2B4C7E', '7751234123500', '7751234123500', '012350', 58.00, 159.90, 'PEN', 2),
    (3, 'JEA-SLIM-30-AZU','30', 'Azul',  '#2B4C7E', '7751234123517', '7751234123517', '012351', 58.00, 159.90, 'PEN', 3),
    (3, 'JEA-SLIM-32-AZU','32', 'Azul',  '#2B4C7E', '7751234123524', '7751234123524', '012352', 58.00, 159.90, 'PEN', 3),
    (4, 'CAM-BASIC-M-GRI','M',  'Gris',  '#8A8A8A', '7751234123531', '7751234123531', '012353', 18.00,  49.90, 'PEN', 4),
    (4, 'CAM-BASIC-L-GRI','L',  'Gris',  '#8A8A8A', '7751234123548', '7751234123548', '012354', 18.00,  49.90, 'PEN', 4),
    (5, 'JEA-RECT-32-AZU','32', 'Azul',  '#31527F', '7751234123555', '7751234123555', '012355', 62.00, 169.90, 'PEN', 2),
    (6, 'TOP-LENT-S-DOR', 'S',  'Dorado','#C9A227', '7751234123562', '7751234123562', '012356', 45.00, 139.90, 'PEN', 1);

-- -----------------------------------------------------------------------------
-- 8. Generación de tags de desarrollo
--    Crea entre 40 y 120 unidades por variante, repartidas por zonas.
--    Los EPC se construyen con el mismo algoritmo del codec (docs/04, §2.1),
--    para que los datos de prueba sean indistinguibles de los reales.
-- -----------------------------------------------------------------------------
DO $$
DECLARE
    v            RECORD;
    i            INT;
    n            INT;
    v_serial     BIGINT;
    v_epc        VARCHAR(48);
    v_zone       BIGINT;
    v_state      tag_state;
BEGIN
    FOR v IN SELECT id, item_reference FROM product_variants ORDER BY id LOOP

        n := 40 + (random() * 80)::INT;

        INSERT INTO product_variant_counters (product_variant_id, last_serial)
        VALUES (v.id, 0)
        ON CONFLICT (product_variant_id) DO NOTHING;

        FOR i IN 1..n LOOP
            SELECT serial_to INTO v_serial FROM reserve_serial_range(v.id, 1);

            -- EPC de desarrollo. El prefijo 3035D919080C corresponde al
            -- header/filter/partition/company prefix del ejemplo de docs/04, §2.1.
            -- Los EPC de PRODUCCIÓN los genera siempre el codec PHP: PostgreSQL
            -- no maneja enteros de 96 bits de forma natural y aquí solo hacen
            -- falta identificadores únicos y con la forma correcta.
            v_epc := upper(
                '3035D919080C' ||
                lpad(to_hex(v.item_reference::BIGINT), 5, '0') ||
                lpad(to_hex(v_serial), 7, '0')
            );

            -- Distribución realista: la mayoría en stock, algunas vendidas,
            -- unas pocas no vistas o perdidas.
            v_state := CASE
                WHEN random() < 0.72 THEN 'en_stock'::tag_state
                WHEN random() < 0.94 THEN 'vendido'::tag_state
                WHEN random() < 0.98 THEN 'no_visto'::tag_state
                ELSE 'perdido'::tag_state
            END;

            -- Reparto por zonas de la tienda 1
            v_zone := CASE
                WHEN random() < 0.55 THEN 2   -- sala
                WHEN random() < 0.75 THEN 5   -- trastienda A
                WHEN random() < 0.92 THEN 6   -- trastienda B
                WHEN random() < 0.97 THEN 1   -- escaparate
                ELSE 3                        -- probadores
            END;

            INSERT INTO tags (
                organization_id, epc, epc_scheme, tid, product_variant_id, state,
                current_location_id, current_zone_id,
                commissioned_at, first_seen_at, last_seen_at, sold_at,
                decoded_company_prefix, decoded_item_reference, decoded_serial
            ) VALUES (
                1,
                v_epc,
                'sgtin-96',
                'E28011' || upper(md5(v_epc)::VARCHAR)::VARCHAR,
                v.id,
                v_state,
                CASE WHEN v_state IN ('en_stock','no_visto') THEN 1 ELSE 1 END,
                CASE WHEN v_state = 'en_stock' THEN v_zone ELSE NULL END,
                now() - (random() * 200 || ' days')::INTERVAL,
                now() - (random() * 200 || ' days')::INTERVAL,
                now() - (random() * 5   || ' days')::INTERVAL,
                CASE WHEN v_state = 'vendido' THEN now() - (random() * 60 || ' days')::INTERVAL END,
                '7751234',
                v.item_reference,
                v_serial
            )
            ON CONFLICT (organization_id, epc) DO NOTHING;
        END LOOP;
    END LOOP;

    RAISE NOTICE 'Semilla completada: % tags generados.', (SELECT count(*) FROM tags);
END $$;

-- -----------------------------------------------------------------------------
-- 9. Movimientos de tarado para los tags generados
--    Sin esto, stock_as_of() no cuadraría con la proyección y el control de
--    integridad nocturno (docs/05, §6) fallaría con datos de semilla.
-- -----------------------------------------------------------------------------
INSERT INTO stock_movements (
    organization_id, tag_id, product_variant_id, movement_type, quantity,
    to_location_id, to_zone_id, state_before, state_after,
    user_id, device_id, reference_type, reason, unit_cost, occurred_at
)
SELECT
    1, t.id, t.product_variant_id, 'tarado'::movement_type, 1,
    1, t.current_zone_id, 'codificado'::tag_state, 'en_stock'::tag_state,
    3, 2, 'seed', 'Carga inicial de datos de desarrollo',
    pv.cost_price, t.commissioned_at
FROM tags t
JOIN product_variants pv ON pv.id = t.product_variant_id;

-- Movimientos de venta para los tags vendidos
INSERT INTO stock_movements (
    organization_id, tag_id, product_variant_id, movement_type, quantity,
    from_location_id, to_location_id, state_before, state_after,
    user_id, reference_type, reason, unit_cost, occurred_at
)
SELECT
    1, t.id, t.product_variant_id, 'venta'::movement_type, 1,
    1, 1, 'en_stock'::tag_state, 'vendido'::tag_state,
    2, 'seed', 'Venta simulada', pv.cost_price, t.sold_at
FROM tags t
JOIN product_variants pv ON pv.id = t.product_variant_id
WHERE t.state = 'vendido' AND t.sold_at IS NOT NULL;

-- -----------------------------------------------------------------------------
-- 10. Ciclo de inventario de ejemplo, ya cerrado
-- -----------------------------------------------------------------------------
INSERT INTO inventory_cycles (
    organization_id, location_id, code, scope, status,
    started_by, started_at, closed_by, closed_at,
    expected_count, counted_count, found_count, missing_count, unexpected_count,
    accuracy_pct, notes
)
SELECT
    1, 1, 'INV-2026-08-031', 'total', 'cerrado',
    2, now() - INTERVAL '7 days', 4, now() - INTERVAL '7 days' + INTERVAL '38 minutes',
    c.total, c.total - 12, c.total - 14, 14, 2,
    ROUND(100.0 * (c.total - 14) / NULLIF(c.total, 0), 3),
    'Ciclo de ejemplo generado por la semilla.'
FROM (SELECT count(*)::INT AS total FROM tags WHERE state IN ('en_stock','no_visto')) c;

-- -----------------------------------------------------------------------------
-- 11. Alertas de ejemplo
-- -----------------------------------------------------------------------------
INSERT INTO alerts (organization_id, location_id, kind, severity, status, tag_id,
                    device_id, title, detail, triggered_at)
SELECT
    1, 1, 'salida_no_vendida'::alert_kind, 2, 'abierta'::alert_status, t.id,
    4, 'Prenda detectada saliendo sin registro de venta',
    jsonb_build_object('confianza', 0.86, 'direccion', 'salida'),
    now() - INTERVAL '2 hours'
FROM tags t WHERE t.state = 'en_stock' LIMIT 1;

INSERT INTO alerts (organization_id, location_id, kind, severity, status,
                    title, detail, triggered_at)
VALUES
    (1, 1, 'epc_desconocido', 4, 'abierta',
     'EPC ajeno detectado repetidamente en el portal',
     '{"epc":"AABBCCDDEEFF00112233445566","veces":47}'::jsonb,
     now() - INTERVAL '1 day'),
    (1, 1, 'tasa_lectura_baja', 3, 'abierta',
     'La zona Probadores quedó por debajo del 60 % en el último ciclo',
     '{"zona":"PROB","porcentaje":42.0}'::jsonb,
     now() - INTERVAL '7 days');

COMMIT;

-- =============================================================================
--  Comprobaciones posteriores a la semilla
-- =============================================================================
-- SELECT count(*) AS tags FROM tags;
-- SELECT state, count(*) FROM tags GROUP BY state ORDER BY 2 DESC;
-- SELECT * FROM v_current_stock LIMIT 20;
-- SELECT * FROM v_replenishment_needed;
-- SELECT * FROM v_inventory_accuracy;
--
-- Verificación de integridad (debe devolver 0 filas):
--   WITH p AS (SELECT product_variant_id, count(*) q FROM tags
--              WHERE state='en_stock' AND current_location_id=1 GROUP BY 1),
--        r AS (SELECT * FROM stock_as_of(1, now()))
--   SELECT * FROM p FULL JOIN r USING (product_variant_id)
--    WHERE COALESCE(p.q,0) <> COALESCE(r.quantity,0);
