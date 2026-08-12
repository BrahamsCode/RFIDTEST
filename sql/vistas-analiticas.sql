-- =============================================================================
--  TRAZA — Vistas analíticas, vistas materializadas y funciones de reporte
--  Documento de referencia: docs/13-kpis-y-analitica.md
-- =============================================================================

BEGIN;

-- -----------------------------------------------------------------------------
-- 1. Stock actual por variante y ubicación (derivado de tags)
-- -----------------------------------------------------------------------------
CREATE OR REPLACE VIEW v_current_stock AS
SELECT
    t.organization_id,
    t.current_location_id            AS location_id,
    t.current_zone_id                AS zone_id,
    t.product_variant_id,
    COUNT(*)                         AS quantity,
    COUNT(*) FILTER (WHERE z.counts_as_sellable) AS sellable_quantity,
    MIN(t.first_seen_at)             AS oldest_unit_at,
    MAX(t.last_seen_at)              AS last_seen_at
FROM tags t
LEFT JOIN zones z ON z.id = t.current_zone_id
WHERE t.state = 'en_stock'
GROUP BY 1, 2, 3, 4;

-- -----------------------------------------------------------------------------
-- 2. Stock valorizado por ubicación
-- -----------------------------------------------------------------------------
CREATE OR REPLACE VIEW v_stock_valuation AS
SELECT
    s.organization_id,
    s.location_id,
    l.name                                       AS location_name,
    p.category_id,
    SUM(s.quantity)                              AS units,
    SUM(s.quantity * COALESCE(pv.cost_price, 0)) AS cost_value,
    SUM(s.quantity * COALESCE(pv.sale_price, 0)) AS retail_value
FROM v_current_stock s
JOIN product_variants pv ON pv.id = s.product_variant_id
JOIN products p          ON p.id  = pv.product_id
JOIN locations l         ON l.id  = s.location_id
GROUP BY 1, 2, 3, 4;

-- -----------------------------------------------------------------------------
-- 3. Exactitud de inventario por ciclo
-- -----------------------------------------------------------------------------
CREATE OR REPLACE VIEW v_inventory_accuracy AS
SELECT
    c.id                AS inventory_cycle_id,
    c.organization_id,
    c.location_id,
    l.name              AS location_name,
    c.code,
    c.started_at,
    c.closed_at,
    EXTRACT(EPOCH FROM (c.closed_at - c.started_at)) / 60 AS duration_minutes,
    c.expected_count,
    c.counted_count,
    c.found_count,
    c.missing_count,
    c.unexpected_count,
    CASE
        WHEN COALESCE(c.expected_count, 0) = 0 THEN NULL
        ELSE ROUND(100.0 * c.found_count / c.expected_count, 3)
    END                 AS read_accuracy_pct,
    CASE
        WHEN COALESCE(c.expected_count, 0) = 0 THEN NULL
        ELSE ROUND(100.0 * (c.expected_count - ABS(c.expected_count - c.counted_count))
                   / c.expected_count, 3)
    END                 AS unit_accuracy_pct
FROM inventory_cycles c
JOIN locations l ON l.id = c.location_id
WHERE c.status = 'cerrado';

-- -----------------------------------------------------------------------------
-- 4. Merma por periodo, ubicación y categoría
-- -----------------------------------------------------------------------------
CREATE OR REPLACE VIEW v_shrinkage AS
SELECT
    date_trunc('month', m.occurred_at)   AS period,
    m.organization_id,
    COALESCE(m.from_location_id, m.to_location_id) AS location_id,
    p.category_id,
    COUNT(*)                             AS units_lost,
    SUM(COALESCE(m.unit_cost, pv.cost_price, 0)) AS cost_lost,
    SUM(COALESCE(pv.sale_price, 0))      AS retail_lost
FROM stock_movements m
JOIN product_variants pv ON pv.id = m.product_variant_id
JOIN products p          ON p.id  = pv.product_id
WHERE m.movement_type IN ('merma', 'ajuste_negativo')
GROUP BY 1, 2, 3, 4;

-- -----------------------------------------------------------------------------
-- 5. Antigüedad de stock (aging) — cuánto lleva cada unidad sin venderse
-- -----------------------------------------------------------------------------
CREATE OR REPLACE VIEW v_stock_aging AS
SELECT
    t.organization_id,
    t.current_location_id AS location_id,
    t.product_variant_id,
    pv.sku,
    p.name                AS product_name,
    t.id                  AS tag_id,
    t.epc,
    t.commissioned_at,
    (now() - t.commissioned_at)                    AS age,
    EXTRACT(DAY FROM (now() - t.commissioned_at))::INT AS age_days,
    CASE
        WHEN now() - t.commissioned_at < INTERVAL '30 days'  THEN '0-30'
        WHEN now() - t.commissioned_at < INTERVAL '60 days'  THEN '31-60'
        WHEN now() - t.commissioned_at < INTERVAL '90 days'  THEN '61-90'
        WHEN now() - t.commissioned_at < INTERVAL '180 days' THEN '91-180'
        ELSE '180+'
    END AS age_bucket
FROM tags t
JOIN product_variants pv ON pv.id = t.product_variant_id
JOIN products p          ON p.id  = pv.product_id
WHERE t.state = 'en_stock'
  AND t.commissioned_at IS NOT NULL;

-- -----------------------------------------------------------------------------
-- 6. Alerta de reposición: hay stock en trastienda pero no en sala
-- -----------------------------------------------------------------------------
CREATE OR REPLACE VIEW v_replenishment_needed AS
WITH by_kind AS (
    SELECT
        t.current_location_id AS location_id,
        t.product_variant_id,
        COUNT(*) FILTER (WHERE z.kind = 'sala')       AS on_floor,
        COUNT(*) FILTER (WHERE z.kind = 'trastienda') AS in_back
    FROM tags t
    JOIN zones z ON z.id = t.current_zone_id
    WHERE t.state = 'en_stock'
    GROUP BY 1, 2
)
SELECT
    b.location_id,
    l.name        AS location_name,
    b.product_variant_id,
    pv.sku,
    p.name        AS product_name,
    pv.size,
    pv.color,
    b.on_floor,
    b.in_back,
    pv.min_stock
FROM by_kind b
JOIN product_variants pv ON pv.id = b.product_variant_id
JOIN products p          ON p.id  = pv.product_id
JOIN locations l         ON l.id  = b.location_id
WHERE b.on_floor <= pv.min_stock
  AND b.in_back  > 0;

-- -----------------------------------------------------------------------------
-- 7. Salud de dispositivos (último latido)
-- -----------------------------------------------------------------------------
CREATE OR REPLACE VIEW v_device_health AS
SELECT DISTINCT ON (d.id)
    d.id            AS device_id,
    d.code,
    d.name,
    d.kind,
    d.location_id,
    d.status,
    h.beat_at       AS last_beat_at,
    now() - COALESCE(h.beat_at, d.created_at) AS since_last_beat,
    h.battery_pct,
    h.temperature_c,
    h.reads_last_min,
    h.buffer_depth,
    CASE
        WHEN h.beat_at IS NULL                        THEN 'sin_datos'
        WHEN now() - h.beat_at > INTERVAL '10 minutes' THEN 'caido'
        WHEN now() - h.beat_at > INTERVAL '2 minutes'  THEN 'degradado'
        ELSE 'ok'
    END AS health
FROM devices d
LEFT JOIN device_health_beats h ON h.device_id = d.id
WHERE d.status = 'activo'
ORDER BY d.id, h.beat_at DESC NULLS LAST;

-- -----------------------------------------------------------------------------
-- 8. VISTA MATERIALIZADA: resumen diario de stock por variante y ubicación
--    Se refresca cada noche. Alimenta los tableros históricos.
-- -----------------------------------------------------------------------------
CREATE MATERIALIZED VIEW IF NOT EXISTS mv_daily_stock AS
SELECT
    date_trunc('day', now())::date AS as_of,
    organization_id,
    location_id,
    product_variant_id,
    SUM(quantity)          AS quantity,
    SUM(sellable_quantity) AS sellable_quantity
FROM v_current_stock
GROUP BY 1, 2, 3, 4
WITH NO DATA;

CREATE UNIQUE INDEX IF NOT EXISTS mv_daily_stock_unique
    ON mv_daily_stock (as_of, location_id, product_variant_id);

-- -----------------------------------------------------------------------------
-- 9. Función: rendimiento de lectura de un ciclo por zona
--    Útil para detectar zonas mal barridas por el operario.
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION cycle_zone_performance(p_cycle_id BIGINT)
RETURNS TABLE(
    zone_id       BIGINT,
    zone_name     VARCHAR,
    expected      BIGINT,
    found         BIGINT,
    missing       BIGINT,
    accuracy_pct  NUMERIC
) AS $$
BEGIN
    RETURN QUERY
    SELECT
        z.id,
        z.name,
        COUNT(e.tag_id)                                        AS expected,
        COUNT(s.tag_id)                                        AS found,
        COUNT(e.tag_id) - COUNT(s.tag_id)                      AS missing,
        CASE WHEN COUNT(e.tag_id) = 0 THEN NULL
             ELSE ROUND(100.0 * COUNT(s.tag_id) / COUNT(e.tag_id), 2)
        END
    FROM inventory_cycle_expected e
    JOIN zones z ON z.id = e.zone_id
    LEFT JOIN inventory_cycle_scans s
           ON s.inventory_cycle_id = e.inventory_cycle_id
          AND s.tag_id = e.tag_id
    WHERE e.inventory_cycle_id = p_cycle_id
    GROUP BY z.id, z.name
    ORDER BY 6 NULLS LAST;
END;
$$ LANGUAGE plpgsql STABLE;

-- -----------------------------------------------------------------------------
-- 10. Función: reconstruir el stock en una fecha pasada desde los movimientos
--     Es la prueba de que stock_movements es la fuente de verdad (ADR-005).
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION stock_as_of(
    p_location_id BIGINT,
    p_at          TIMESTAMPTZ
) RETURNS TABLE(product_variant_id BIGINT, quantity BIGINT) AS $$
BEGIN
    RETURN QUERY
    WITH last_state AS (
        SELECT DISTINCT ON (m.tag_id)
               m.tag_id,
               m.product_variant_id,
               m.state_after,
               m.to_location_id
        FROM stock_movements m
        WHERE m.occurred_at <= p_at
          AND m.tag_id IS NOT NULL
        ORDER BY m.tag_id, m.occurred_at DESC, m.id DESC
    )
    SELECT ls.product_variant_id, COUNT(*)::BIGINT
    FROM last_state ls
    WHERE ls.state_after = 'en_stock'
      AND ls.to_location_id = p_location_id
    GROUP BY ls.product_variant_id;
END;
$$ LANGUAGE plpgsql STABLE;

-- -----------------------------------------------------------------------------
-- 11. Función: detección de posible clonación de tags
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION detect_cloned_tags(p_since TIMESTAMPTZ)
RETURNS TABLE(epc VARCHAR, distinct_tids BIGINT, tids TEXT[]) AS $$
BEGIN
    RETURN QUERY
    SELECT r.epc,
           COUNT(DISTINCT r.tid)          AS distinct_tids,
           array_agg(DISTINCT r.tid)::TEXT[]
    FROM tag_reads r
    WHERE r.read_at >= p_since
      AND r.tid IS NOT NULL
    GROUP BY r.epc
    HAVING COUNT(DISTINCT r.tid) > 1;
END;
$$ LANGUAGE plpgsql STABLE;

COMMIT;
