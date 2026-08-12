# 05 — Modelo de datos

DDL completo: `sql/schema.sql` · Vistas: `sql/vistas-analiticas.sql` · Semilla: `sql/seeds.sql`

---

## 1. Diagrama entidad-relación (simplificado)

```
organizations
     │
     ├──▶ locations ──▶ zones ◀─────────────┐
     │        │                              │
     │        └──▶ devices ──▶ device_antennas
     │
     ├──▶ suppliers ─┐
     ├──▶ categories │
     ├──▶ seasons ───┤
     │               ▼
     └──▶ products ──▶ product_variants ──▶ product_variant_counters
                              │
                              │  1..N
                              ▼
                      ┌───────────────┐
                      │     tags      │◀── tag_batches
                      │  (EPC único)  │
                      └───┬───────┬───┘
                          │       │
         ┌────────────────┘       └──────────────┐
         ▼                                        ▼
  stock_movements                        inventory_cycle_scans
  (append-only,                          inventory_cycle_expected
   fuente de verdad)                             │
         │                                        ▼
         │                                inventory_cycles
         │                                        │
         │                                        ▼
         │                              inventory_cycle_results
         │
         ├──▶ referencia a: receiving_orders / transfers /
         │                  sale_transactions / inventory_cycles
         ▼
   stock_snapshots        alerts        portal_events        audit_logs

  tag_reads  (particionada por mes, retención 90 días — NO tiene FK a tags
              a propósito: debe aceptar EPC desconocidos)
```

---

## 2. Las cuatro tablas que hay que entender

### 2.1 `tags` — la identidad

Una fila **por unidad física de prenda**. Es lo que distingue este sistema de uno de código de barras.

Contiene deliberadamente estado desnormalizado (`state`, `current_location_id`, `current_zone_id`, `last_seen_at`). Esto viola la pureza del event sourcing, pero:

- El 95 % de las consultas operativas son "¿qué hay ahora en esta tienda?".
- Recalcular desde `stock_movements` en cada consulta sería inaceptablemente lento.
- La consistencia se garantiza porque **solo** `StockMovementService` escribe en ambas tablas, y siempre dentro de la misma transacción.

Es un patrón de *proyección síncrona*. La función `stock_as_of()` permite verificar en cualquier momento que la proyección coincide con los movimientos; se ejecuta como control de integridad nocturno.

### 2.2 `stock_movements` — la verdad

Append-only, protegida por trigger contra `UPDATE` y `DELETE`. Cada fila responde: **qué** prenda, **qué** pasó, **de dónde** a **dónde**, **quién**, **con qué dispositivo**, **por qué**, **cuándo**.

Un movimiento sin `tag_id` es válido: representa operaciones por SKU sin RFID (venta de prenda con tag arrancado, ajuste manual). El sistema no puede exigir RFID para todo, o una avería paraliza la tienda.

Corrección de errores: **nunca se edita un movimiento**. Se registra un movimiento compensatorio con `reason` explicando la corrección. Esto es lo que exige una auditoría contable y lo que permite investigar merma con credibilidad.

### 2.3 `tag_reads` — el registro crudo

Cada detección física de un tag, tal cual llegó del lector. Alto volumen, retención corta.

**No tiene clave foránea hacia `tags`.** Es intencional: el lector detecta EPC que no conoces (tags ajenos, prendas no taradas, tarjetas de acceso). Una FK haría fallar la ingesta ante lo desconocido, que es exactamente lo que más necesitas registrar.

Particionado por rango mensual sobre `read_at`, con índice BRIN. Justificación del BRIN: los datos llegan naturalmente ordenados por tiempo, la correlación física es casi perfecta, y un BRIN ocupa kilobytes donde un B-tree ocuparía gigabytes.

Estimación de volumen:

| Escenario | Lecturas/mes | Tamaño aprox. |
|---|---:|---:|
| 1 tienda, 20 000 prendas, 4 ciclos/mes, 3 lecturas por tag por ciclo | ~240 000 | ~40 MB |
| + portal con 400 tránsitos/día × 20 lecturas | ~240 000 | ~40 MB |
| 5 tiendas | ~2.4 M | ~400 MB/mes |
| 20 tiendas | ~10 M | ~1.6 GB/mes |

Con retención de 90 días en caliente, incluso 20 tiendas se mantienen bajo 5 GB. Perfectamente cómodo.

### 2.4 `inventory_cycles` + `_expected` + `_scans` — la conciliación

El diseño clave es **`inventory_cycle_expected`**: al arrancar un ciclo se congela la lista de EPC que *deberían* estar. Sin esta foto, la conciliación no sería reproducible: si alguien vende una prenda a mitad del conteo, el resultado cambiaría según cuándo lo calcules.

Conciliación:

```
esperados  = SELECT tag_id FROM inventory_cycle_expected WHERE cycle = X
contados   = SELECT tag_id FROM inventory_cycle_scans    WHERE cycle = X

encontrados  = esperados ∩ contados      →  sin acción
faltantes    = esperados − contados      →  missed_cycles++ ; posible merma
inesperados  = contados − esperados      →  ajuste positivo o transferencia no registrada
```

---

## 3. Diccionario de datos — campos no obvios

| Tabla.campo | Por qué existe |
|---|---|
| `organizations.epc_filter_mask` | Prefijo hex propio. El middleware descarta toda lectura que no case. Primera defensa contra sobre-lectura del local vecino |
| `products.rfid_difficulty` | 1–5. Se rellena tras probar el material. Permite explicar por qué ciertos SKU siempre tienen peor exactitud, en lugar de culpar al operario |
| `tags.tid` | Identificador de fábrica del chip. Si un mismo EPC aparece con dos TID → clonación |
| `tags.missed_cycles` | Contador de ciclos consecutivos sin detectar. Alimenta la transición `en_stock → no_visto → perdido` |
| `tags.replaces_tag_id` | Cuando se re-etiqueta una prenda cuyo tag se arrancó. Evita contarla dos veces |
| `zones.counts_as_sellable` | Distingue "hay stock" de "hay stock que el cliente puede encontrar". La trastienda no vende |
| `device_antennas.rssi_threshold` | Umbral por antena, no global. La antena de caja necesita un umbral mucho más alto que la de un pasillo |
| `devices.regulatory_region` | Obligatorio. Impide dar de alta un lector sin declarar su perfil regulatorio (documento 01, §3) |
| `read_profiles.session` | Sesión Gen2 (0–3). Cambiar esto es la diferencia entre un inventario de 20 min y uno de 2 h |
| `read_profiles.min_read_count` | Nº mínimo de detecciones en la ventana para aceptar la lectura. Filtro [4] del pipeline |
| `stock_movements.state_before/after` | Redundante con `tags.state`, pero permite reconstruir la máquina de estados históricamente sin recorrer toda la tabla |
| `portal_events.evidence` | JSONB con la secuencia de antenas y RSSI del cruce. Es la prueba que se revisa cuando alguien discute una alarma |
| `unknown_epcs` | Registro de EPC ajenos. Al principio parece ruido; a los tres meses es el mapa de qué otros sistemas RFID hay a tu alrededor |

---

## 4. Índices: justificación

| Índice | Consulta que sirve |
|---|---|
| `tags_location_state_idx` (parcial) | "Stock actual de la tienda 3" — la consulta más frecuente del sistema. Parcial sobre `en_stock`/`no_visto` porque las prendas vendidas son el 90 % de la tabla histórica y nunca se consultan aquí |
| `tags_variant_state_idx` | "¿Cuántas poleras M negras hay?" |
| `tags_epc_trgm_idx` | Búsqueda por EPC parcial en la interfaz de soporte |
| `tag_reads_read_at_brin` | Barridos por rango temporal en tabla masiva |
| `tag_reads_epc_idx` | "Historial de detecciones de este EPC" — la consulta de diagnóstico |
| `stock_movements_tag_idx` | Ficha de trazabilidad de una prenda |
| `alerts_open_idx` (parcial) | Bandeja de alertas abiertas. Parcial: las resueltas no se consultan en tiempo real |
| `sale_lines_tag_idx` (parcial) | Verificación de venta al detectar un tránsito de portal — camino crítico de latencia |

**Índices deliberadamente ausentes**: no hay índice sobre `stock_movements.occurred_at` solo. Los informes históricos siempre filtran además por variante, tipo o ubicación, y los índices compuestos existentes los cubren. Un índice más en una tabla de escritura intensiva cuesta en cada `INSERT`.

---

## 5. Estrategia de retención y archivado

| Tabla | Caliente | Frío | Purga |
|---|---|---|---|
| `tag_reads` | 90 días (particiones) | Exportación mensual a Parquet en MinIO | `drop_old_tag_reads_partitions(3)` |
| `device_health_beats` | 30 días | — | `DELETE` mensual |
| `stock_movements` | **Siempre** | — | Nunca. Requisito contable |
| `inventory_cycle_scans` | 24 meses | Parquet | Purga de ciclos cerrados > 24 meses |
| `audit_logs` | 24 meses | Parquet | — |
| `unknown_epcs` | 12 meses | — | Purga de resueltos |
| `portal_events` | 12 meses | Parquet | — |

### Job de rotación (mensual, día 20)

```sql
-- Crea la partición del mes siguiente y del subsiguiente (margen de seguridad)
SELECT ensure_tag_reads_partition((now() + INTERVAL '1 month')::date);
SELECT ensure_tag_reads_partition((now() + INTERVAL '2 months')::date);

-- Verifica que la partición DEFAULT esté vacía. Si no lo está, hay un bug
-- en la rotación y hay que investigar antes de purgar.
SELECT count(*) AS filas_en_default FROM tag_reads_default;

-- Purga particiones anteriores a 3 meses
SELECT * FROM drop_old_tag_reads_partitions(3);
```

En Laravel se programa así:

```php
// routes/console.php
Schedule::command('traza:rotate-partitions')
    ->monthlyOn(20, '03:00')
    ->onOneServer()
    ->emailOutputOnFailure(config('traza.ops_email'));
```

---

## 6. Control de integridad nocturno

La proyección `tags` puede desincronizarse de `stock_movements` por un bug. El control lo detecta antes de que se convierta en un problema de inventario.

```sql
-- Discrepancias entre la proyección y la reconstrucción desde movimientos
WITH proyectado AS (
    SELECT product_variant_id, COUNT(*) AS qty
    FROM tags
    WHERE state = 'en_stock' AND current_location_id = :location_id
    GROUP BY 1
),
reconstruido AS (
    SELECT * FROM stock_as_of(:location_id, now())
)
SELECT
    COALESCE(p.product_variant_id, r.product_variant_id) AS product_variant_id,
    COALESCE(p.qty, 0) AS proyectado,
    COALESCE(r.quantity, 0) AS reconstruido,
    COALESCE(p.qty, 0) - COALESCE(r.quantity, 0) AS diferencia
FROM proyectado p
FULL OUTER JOIN reconstruido r USING (product_variant_id)
WHERE COALESCE(p.qty, 0) <> COALESCE(r.quantity, 0);
```

Si devuelve filas → alerta crítica al equipo técnico. **Este control no debe silenciarse nunca**; es la garantía de que el número que ve el dueño de la tienda es real.

---

## 7. Migraciones de Laravel

El esquema se implementa como migraciones. Orden obligatorio (por dependencias de FK):

```
2026_08_01_000001_create_organizations_table
2026_08_01_000002_create_locations_and_zones_tables
2026_08_01_000003_create_users_and_roles_tables
2026_08_01_000010_create_catalog_tables          (suppliers, categories, seasons, products, variants)
2026_08_01_000011_create_variant_counters_table
2026_08_01_000020_create_devices_tables          (devices, antennas, read_profiles, health)
2026_08_01_000030_create_tags_tables             (tag_batches, tags, replacements, unknown_epcs)
2026_08_01_000040_create_tag_reads_partitioned   (DDL crudo vía DB::statement)
2026_08_01_000050_create_stock_movements_table   (+ trigger append-only)
2026_08_01_000060_create_inventory_cycles_tables
2026_08_01_000070_create_receiving_and_transfers_tables
2026_08_01_000080_create_sales_tables
2026_08_01_000090_create_alerts_and_portal_events
2026_08_01_000100_create_snapshots_and_audit
2026_08_01_000110_create_functions_and_views     (DB::unprepared del SQL de funciones)
```

### Nota sobre tipos enumerados

Los `CREATE TYPE ... AS ENUM` de PostgreSQL no tienen soporte nativo en el constructor de esquemas de Laravel. Se crean con SQL crudo:

```php
public function up(): void
{
    DB::unprepared(<<<'SQL'
        CREATE TYPE tag_state AS ENUM (
            'creado','codificado','en_stock','en_transito','no_visto',
            'perdido','vendido','danado','baja','anulado'
        );
    SQL);

    Schema::create('tags', function (Blueprint $table) {
        $table->id();
        // ...
    });

    // La columna de tipo enum se añade aparte
    DB::statement("ALTER TABLE tags ADD COLUMN state tag_state NOT NULL DEFAULT 'creado'");
}
```

> **Alternativa considerada y descartada**: usar `VARCHAR` + `CHECK`. Es más portable y más fácil de migrar (añadir un valor a un ENUM requiere `ALTER TYPE`). Se optó por ENUM porque el conjunto de estados es una decisión de diseño estable y el ENUM da validación a nivel de base de datos con menos ruido. Si en el futuro los estados cambian con frecuencia, migrar a `VARCHAR + CHECK` es una migración sencilla.

---

## 8. Consultas de referencia

```sql
-- Stock disponible en sala de la tienda 'LIM-01', por SKU
SELECT pv.sku, p.name, pv.size, pv.color, s.sellable_quantity
FROM v_current_stock s
JOIN product_variants pv ON pv.id = s.product_variant_id
JOIN products p          ON p.id  = pv.product_id
JOIN locations l         ON l.id  = s.location_id
WHERE l.code = 'LIM-01' AND s.sellable_quantity > 0
ORDER BY p.name, pv.size;

-- Trazabilidad completa de una prenda
SELECT m.occurred_at, m.movement_type, m.state_before, m.state_after,
       fl.name AS desde, tl.name AS hacia, u.name AS usuario, d.name AS dispositivo,
       m.reason
FROM stock_movements m
LEFT JOIN locations fl ON fl.id = m.from_location_id
LEFT JOIN locations tl ON tl.id = m.to_location_id
LEFT JOIN users u      ON u.id  = m.user_id
LEFT JOIN devices d    ON d.id  = m.device_id
WHERE m.tag_id = (SELECT id FROM tags WHERE epc = '3035D919080C0E403B9ACA2A')
ORDER BY m.occurred_at;

-- Prendas que llevan más de 90 días sin venderse en una tienda
SELECT age_bucket, COUNT(*) AS unidades
FROM v_stock_aging
WHERE location_id = 1
GROUP BY age_bucket
ORDER BY age_bucket;

-- Merma del último trimestre por categoría
SELECT c.name AS categoria, SUM(s.units_lost) AS unidades, SUM(s.cost_lost) AS costo
FROM v_shrinkage s
JOIN categories c ON c.id = s.category_id
WHERE s.period >= date_trunc('quarter', now() - INTERVAL '3 months')
GROUP BY c.name
ORDER BY costo DESC;

-- Zonas peor barridas en el último ciclo de la tienda 1
SELECT * FROM cycle_zone_performance(
    (SELECT id FROM inventory_cycles
      WHERE location_id = 1 AND status = 'cerrado'
      ORDER BY closed_at DESC LIMIT 1)
);
```
