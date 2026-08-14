-- =============================================================================
--  TRAZA — Esquema de base de datos
--  PostgreSQL 16
--  Documento de referencia: docs/05-modelo-de-datos.md
-- =============================================================================
--  Convenciones:
--    · Nombres de tabla en snake_case plural, en inglés.
--    · Toda tabla de negocio lleva organization_id (ver ADR-006).
--    · Timestamps siempre TIMESTAMPTZ; la aplicación trabaja en UTC y
--      presenta en America/Lima.
--    · Los identificadores externos usan BIGINT GENERATED ALWAYS AS IDENTITY.
--    · Los EPC se guardan como VARCHAR en HEX MAYÚSCULA, sin separadores.
-- =============================================================================

BEGIN;

CREATE EXTENSION IF NOT EXISTS "pgcrypto";
CREATE EXTENSION IF NOT EXISTS "btree_gin";
CREATE EXTENSION IF NOT EXISTS "pg_trgm";

-- =============================================================================
-- 1. TIPOS ENUMERADOS
-- =============================================================================

CREATE TYPE tag_state AS ENUM (
    'creado',       -- EPC reservado, sin soporte físico
    'codificado',   -- escrito en un inlay, sin prenda asignada
    'en_stock',     -- asociado a prenda y presente en una ubicación
    'en_transito',  -- enviado entre ubicaciones, no recibido
    'no_visto',     -- no detectado en N ciclos consecutivos
    'perdido',      -- declarado merma
    'vendido',      -- salió por caja
    'danado',       -- prenda inservible
    'baja',         -- fuera de inventario definitivamente
    'anulado'       -- EPC descartado antes de usarse
);

CREATE TYPE movement_type AS ENUM (
    'tarado',            -- alta: EPC asociado a prenda
    'recepcion',         -- entrada por compra
    'venta',             -- salida por caja
    'devolucion_cliente',-- entrada por devolución
    'devolucion_prov',   -- salida hacia proveedor
    'transferencia_out', -- salida por transferencia
    'transferencia_in',  -- entrada por transferencia
    'ajuste_positivo',   -- aparición en conteo
    'ajuste_negativo',   -- desaparición en conteo
    'merma',             -- declaración de pérdida
    'dano',              -- baja por deterioro
    'cambio_zona',       -- movimiento interno entre zonas
    'reetiquetado',      -- sustitución de tag
    'anulacion'          -- descarte de EPC
);

CREATE TYPE location_type AS ENUM ('tienda', 'almacen', 'taller', 'transito', 'virtual');

CREATE TYPE zone_kind AS ENUM (
    'sala',        -- piso de venta
    'trastienda',  -- almacén de la tienda
    'probador',
    'escaparate',
    'caja',
    'recepcion',
    'salida',
    'otro'
);

CREATE TYPE device_kind AS ENUM ('handheld', 'lector_fijo', 'impresora', 'edge');
CREATE TYPE device_status AS ENUM ('activo', 'inactivo', 'mantenimiento', 'baja');

CREATE TYPE cycle_status AS ENUM ('borrador', 'en_curso', 'pausado', 'conciliando', 'cerrado', 'cancelado');
CREATE TYPE cycle_scope  AS ENUM ('total', 'zona', 'categoria', 'muestreo');

CREATE TYPE alert_kind AS ENUM (
    'salida_no_vendida',   -- EPC cruzó el portal sin registrarse la venta
    'epc_desconocido',
    'epc_duplicado',
    'tid_discrepante',
    'reaparicion_perdido',
    'lector_sin_latido',
    'tasa_lectura_baja',
    'stock_negativo',
    'reposicion_sala'
);

CREATE TYPE alert_status AS ENUM ('abierta', 'en_revision', 'resuelta', 'descartada');

CREATE TYPE epc_scheme AS ENUM ('sgtin-96', 'gid-96', 'propietario');

-- =============================================================================
-- 2. ORGANIZACIÓN, UBICACIONES Y USUARIOS
-- =============================================================================

CREATE TABLE organizations (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name            VARCHAR(160) NOT NULL,
    tax_id          VARCHAR(20),                 -- RUC en Perú
    gs1_company_prefix VARCHAR(12),              -- NULL si aún no hay GS1
    default_epc_scheme epc_scheme NOT NULL DEFAULT 'gid-96',
    epc_filter_mask VARCHAR(64),                 -- prefijo hex propio; base del filtro del middleware
    timezone        VARCHAR(64) NOT NULL DEFAULT 'America/Lima',
    settings        JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE locations (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organization_id BIGINT NOT NULL REFERENCES organizations(id),
    code            VARCHAR(24)  NOT NULL,
    name            VARCHAR(160) NOT NULL,
    kind            location_type NOT NULL DEFAULT 'tienda',
    address         TEXT,
    latitude        NUMERIC(10,7),
    longitude       NUMERIC(10,7),
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    settings        JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT locations_code_unique UNIQUE (organization_id, code)
);

CREATE TABLE zones (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    location_id     BIGINT NOT NULL REFERENCES locations(id) ON DELETE CASCADE,
    code            VARCHAR(24) NOT NULL,
    name            VARCHAR(120) NOT NULL,
    kind            zone_kind NOT NULL DEFAULT 'sala',
    -- Las zonas de venta cuentan para "disponible en sala"; trastienda no.
    counts_as_sellable BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order      INT NOT NULL DEFAULT 0,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT zones_code_unique UNIQUE (location_id, code)
);

CREATE TABLE users (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organization_id BIGINT NOT NULL REFERENCES organizations(id),
    name            VARCHAR(160) NOT NULL,
    email           VARCHAR(190) NOT NULL UNIQUE,
    password        VARCHAR(255) NOT NULL,
    default_location_id BIGINT REFERENCES locations(id),
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    last_login_at   TIMESTAMPTZ,
    remember_token  VARCHAR(100),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE roles (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code        VARCHAR(48) NOT NULL UNIQUE,
    name        VARCHAR(120) NOT NULL,
    permissions JSONB NOT NULL DEFAULT '[]'::jsonb
);

CREATE TABLE role_user (
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role_id BIGINT NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, role_id)
);

-- =============================================================================
-- 3. CATÁLOGO
-- =============================================================================

CREATE TABLE suppliers (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organization_id BIGINT NOT NULL REFERENCES organizations(id),
    code            VARCHAR(32) NOT NULL,
    name            VARCHAR(200) NOT NULL,
    tax_id          VARCHAR(20),
    contact         JSONB NOT NULL DEFAULT '{}'::jsonb,
    -- Rango EPC delegado al proveedor para source tagging (Fase 3)
    delegated_epc_range JSONB,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT suppliers_code_unique UNIQUE (organization_id, code)
);

CREATE TABLE categories (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organization_id BIGINT NOT NULL REFERENCES organizations(id),
    parent_id       BIGINT REFERENCES categories(id) ON DELETE SET NULL,
    code            VARCHAR(32) NOT NULL,
    name            VARCHAR(120) NOT NULL,
    path            TEXT,                     -- materializada: '/mujer/polos/oversize'
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT categories_code_unique UNIQUE (organization_id, code)
);

CREATE TABLE seasons (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organization_id BIGINT NOT NULL REFERENCES organizations(id),
    code            VARCHAR(24) NOT NULL,      -- 'V26', 'I26'
    name            VARCHAR(80) NOT NULL,
    starts_on       DATE,
    ends_on         DATE,
    CONSTRAINT seasons_code_unique UNIQUE (organization_id, code)
);

CREATE TABLE products (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organization_id BIGINT NOT NULL REFERENCES organizations(id),
    category_id     BIGINT REFERENCES categories(id),
    season_id       BIGINT REFERENCES seasons(id),
    supplier_id     BIGINT REFERENCES suppliers(id),
    code            VARCHAR(48) NOT NULL,      -- código de modelo interno
    name            VARCHAR(200) NOT NULL,
    description     TEXT,
    brand           VARCHAR(120),
    composition     VARCHAR(200),              -- '95% algodón, 5% elastano'
    -- El material afecta al rendimiento RFID; se registra para diagnóstico
    rfid_difficulty SMALLINT NOT NULL DEFAULT 1
        CONSTRAINT products_rfid_difficulty_range CHECK (rfid_difficulty BETWEEN 1 AND 5),
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT products_code_unique UNIQUE (organization_id, code)
);

CREATE TABLE product_variants (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    product_id      BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    sku             VARCHAR(64) NOT NULL,
    size            VARCHAR(24),
    color           VARCHAR(48),
    color_hex       CHAR(7),
    barcode         VARCHAR(20),               -- EAN-13 / UPC impreso
    gtin13          VARCHAR(14),               -- base para el SGTIN
    item_reference  VARCHAR(8),                -- campo item ref del SGTIN
    cost_price      NUMERIC(12,4),
    sale_price      NUMERIC(12,4),
    currency        CHAR(3) NOT NULL DEFAULT 'PEN',
    min_stock       INT NOT NULL DEFAULT 0,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT product_variants_sku_unique UNIQUE (sku),
    CONSTRAINT product_variants_gtin_unique UNIQUE (gtin13)
);

CREATE INDEX product_variants_product_idx ON product_variants (product_id);
CREATE INDEX product_variants_barcode_idx ON product_variants (barcode) WHERE barcode IS NOT NULL;

-- Contador de seriales por variante. Ver docs/04, §3.2.
CREATE TABLE product_variant_counters (
    product_variant_id BIGINT PRIMARY KEY REFERENCES product_variants(id) ON DELETE CASCADE,
    last_serial        BIGINT NOT NULL DEFAULT 0
        CONSTRAINT counters_serial_range CHECK (last_serial >= 0 AND last_serial <= 274877906943),
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- =============================================================================
-- 4. DISPOSITIVOS
-- =============================================================================

CREATE TABLE devices (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organization_id BIGINT NOT NULL REFERENCES organizations(id),
    location_id     BIGINT REFERENCES locations(id),
    code            VARCHAR(32) NOT NULL,
    name            VARCHAR(120) NOT NULL,
    kind            device_kind NOT NULL,
    manufacturer    VARCHAR(80),
    model           VARCHAR(80),
    serial_number   VARCHAR(80),
    ip_address      INET,
    mac_address     MACADDR,
    firmware        VARCHAR(48),
    -- Perfil regulatorio: obligatorio. Ver docs/01, §3.
    regulatory_region VARCHAR(24) NOT NULL,   -- 'FCC-PE', 'ETSI', ...
    status          device_status NOT NULL DEFAULT 'activo',
    api_token_hash  VARCHAR(255),             -- token de dispositivo, hasheado
    last_seen_at    TIMESTAMPTZ,
    settings        JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT devices_code_unique UNIQUE (organization_id, code)
);

-- El token es lo que identifica al dispositivo al autenticarse: el código no
-- vale, porque es único solo dentro de la organización y dos tiendas pueden
-- tener cada una su 'EDGE-01'. La búsqueda por hash corre en cada petición de
-- ingesta, de ahí el índice. Único además porque dos dispositivos no pueden
-- compartir token; los que aún no se han dado de alta tienen NULL, y en
-- PostgreSQL un índice único admite varios NULL.
CREATE UNIQUE INDEX devices_api_token_hash_unique ON devices (api_token_hash);

CREATE TABLE device_antennas (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    device_id       BIGINT NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    zone_id         BIGINT REFERENCES zones(id),
    port_number     SMALLINT NOT NULL,
    label           VARCHAR(80),
    -- Geometría para depuración e informes
    mount_height_cm SMALLINT,
    tilt_degrees    SMALLINT,
    side            VARCHAR(16),              -- 'interior' | 'exterior' en un portal
    tx_power_dbm    NUMERIC(5,2) NOT NULL DEFAULT 27.0,
    rssi_threshold  NUMERIC(6,2),             -- filtro [2] del pipeline
    is_enabled      BOOLEAN NOT NULL DEFAULT TRUE,
    CONSTRAINT device_antennas_port_unique UNIQUE (device_id, port_number)
);

-- Perfiles de lectura reutilizables (Gen2: sesión, Q, potencia, ventanas)
CREATE TABLE read_profiles (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organization_id BIGINT NOT NULL REFERENCES organizations(id),
    code            VARCHAR(32) NOT NULL,
    name            VARCHAR(120) NOT NULL,
    session         SMALLINT NOT NULL DEFAULT 2
        CONSTRAINT read_profiles_session_range CHECK (session BETWEEN 0 AND 3),
    target          CHAR(1) NOT NULL DEFAULT 'A'
        CONSTRAINT read_profiles_target_valid CHECK (target IN ('A','B')),
    initial_q       SMALLINT NOT NULL DEFAULT 4
        CONSTRAINT read_profiles_q_range CHECK (initial_q BETWEEN 0 AND 15),
    tx_power_dbm    NUMERIC(5,2) NOT NULL DEFAULT 27.0,
    dedup_window_ms INT NOT NULL DEFAULT 1000,
    min_read_count  SMALLINT NOT NULL DEFAULT 1,
    rssi_threshold  NUMERIC(6,2),
    read_tid        BOOLEAN NOT NULL DEFAULT FALSE,
    notes           TEXT,
    CONSTRAINT read_profiles_code_unique UNIQUE (organization_id, code)
);

CREATE TABLE device_health_beats (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    device_id     BIGINT NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    beat_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    cpu_percent   NUMERIC(5,2),
    temperature_c NUMERIC(5,2),
    battery_pct   SMALLINT,
    reads_last_min INT,
    buffer_depth  INT,                        -- lecturas pendientes de sincronizar
    payload       JSONB NOT NULL DEFAULT '{}'::jsonb
);

CREATE INDEX device_health_beats_device_time_idx
    ON device_health_beats (device_id, beat_at DESC);

-- =============================================================================
-- 5. TAGS
-- =============================================================================

CREATE TABLE tag_batches (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organization_id BIGINT NOT NULL REFERENCES organizations(id),
    product_variant_id BIGINT REFERENCES product_variants(id),
    device_id       BIGINT REFERENCES devices(id),   -- impresora usada
    created_by      BIGINT REFERENCES users(id),
    quantity        INT NOT NULL CONSTRAINT tag_batches_qty_positive CHECK (quantity > 0),
    serial_from     BIGINT NOT NULL,
    serial_to       BIGINT NOT NULL,
    printed_ok      INT NOT NULL DEFAULT 0,
    printed_void    INT NOT NULL DEFAULT 0,
    notes           TEXT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    completed_at    TIMESTAMPTZ,
    CONSTRAINT tag_batches_serial_order CHECK (serial_to >= serial_from)
);

CREATE TABLE tags (
    id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organization_id    BIGINT NOT NULL REFERENCES organizations(id),
    epc                VARCHAR(48) NOT NULL,
    epc_scheme         epc_scheme NOT NULL DEFAULT 'sgtin-96',
    tid                VARCHAR(48),                     -- testigo antifraude
    product_variant_id BIGINT REFERENCES product_variants(id),
    tag_batch_id       BIGINT REFERENCES tag_batches(id),
    state              tag_state NOT NULL DEFAULT 'creado',
    -- Estado espacial actual (desnormalizado a propósito; ver ADR-005)
    current_location_id BIGINT REFERENCES locations(id),
    current_zone_id     BIGINT REFERENCES zones(id),
    -- Metadatos de ciclo de vida
    commissioned_at    TIMESTAMPTZ,
    first_seen_at      TIMESTAMPTZ,
    last_seen_at       TIMESTAMPTZ,
    sold_at            TIMESTAMPTZ,
    missed_cycles      SMALLINT NOT NULL DEFAULT 0,     -- ciclos consecutivos sin ver
    -- Sustitución de tag: si esta prenda tenía otro EPC antes
    replaces_tag_id    BIGINT REFERENCES tags(id),
    -- Campos decodificados, materializados para consultas rápidas
    decoded_company_prefix VARCHAR(12),
    decoded_item_reference VARCHAR(8),
    decoded_serial     BIGINT,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT tags_epc_unique UNIQUE (organization_id, epc),
    CONSTRAINT tags_epc_hex CHECK (epc ~ '^[0-9A-F]+$')
);

CREATE INDEX tags_state_idx            ON tags (organization_id, state);
CREATE INDEX tags_variant_state_idx    ON tags (product_variant_id, state);
CREATE INDEX tags_location_state_idx   ON tags (current_location_id, state)
    WHERE state IN ('en_stock', 'no_visto');
CREATE INDEX tags_zone_idx             ON tags (current_zone_id) WHERE state = 'en_stock';
CREATE INDEX tags_last_seen_idx        ON tags (last_seen_at DESC NULLS LAST);
CREATE INDEX tags_tid_idx              ON tags (tid) WHERE tid IS NOT NULL;
CREATE INDEX tags_epc_trgm_idx         ON tags USING gin (epc gin_trgm_ops);

-- Sustituciones de tag (prenda re-etiquetada). Evita doble conteo.
CREATE TABLE tag_replacements (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    old_tag_id    BIGINT NOT NULL REFERENCES tags(id),
    new_tag_id    BIGINT NOT NULL REFERENCES tags(id),
    reason        VARCHAR(64) NOT NULL,       -- 'ilegible' | 'arrancado' | 'dañado'
    performed_by  BIGINT REFERENCES users(id),
    performed_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT tag_replacements_distinct CHECK (old_tag_id <> new_tag_id),
    CONSTRAINT tag_replacements_new_unique UNIQUE (new_tag_id)
);

-- EPC vistos que no pertenecen al sistema. Solo para investigación.
CREATE TABLE unknown_epcs (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    epc           VARCHAR(48) NOT NULL,
    location_id   BIGINT REFERENCES locations(id),
    device_id     BIGINT REFERENCES devices(id),
    first_seen_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_seen_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    seen_count    INT NOT NULL DEFAULT 1,
    resolved      BOOLEAN NOT NULL DEFAULT FALSE,
    notes         TEXT,
    CONSTRAINT unknown_epcs_unique UNIQUE (epc, location_id)
);

-- =============================================================================
-- 6. LECTURAS CRUDAS (tabla particionada)
-- =============================================================================
-- Volumen alto, retención corta. Ver ADR-002 y docs/05, §5.

CREATE TABLE tag_reads (
    id            BIGINT GENERATED ALWAYS AS IDENTITY,
    read_at       TIMESTAMPTZ NOT NULL,
    epc           VARCHAR(48) NOT NULL,
    tid           VARCHAR(48),
    device_id     BIGINT NOT NULL,
    antenna_port  SMALLINT,
    location_id   BIGINT,
    zone_id       BIGINT,
    rssi          NUMERIC(6,2),
    phase_angle   NUMERIC(8,3),
    doppler_hz    NUMERIC(8,3),
    read_count    SMALLINT NOT NULL DEFAULT 1,
    session_ref   UUID,               -- agrupa lecturas de una misma sesión/ciclo
    inventory_cycle_id BIGINT,
    ingested_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (id, read_at)
) PARTITION BY RANGE (read_at);

-- Particiones iniciales. La rotación la crea el job mensual (ver §11).
CREATE TABLE tag_reads_2026_08 PARTITION OF tag_reads
    FOR VALUES FROM ('2026-08-01') TO ('2026-09-01');
CREATE TABLE tag_reads_2026_09 PARTITION OF tag_reads
    FOR VALUES FROM ('2026-09-01') TO ('2026-10-01');
CREATE TABLE tag_reads_2026_10 PARTITION OF tag_reads
    FOR VALUES FROM ('2026-10-01') TO ('2026-11-01');
-- ⚠️ La partición DEFAULT es una red de seguridad contra pérdida de datos si el
--    job de rotación falla. Pero si llega a contener filas de un mes futuro,
--    CREATE TABLE ... PARTITION OF fallará para ese mes. El job de rotación debe
--    ejecutarse con antelación (día 20 del mes anterior) y alertar si la default
--    deja de estar vacía.
CREATE TABLE tag_reads_default PARTITION OF tag_reads DEFAULT;

-- BRIN es ideal aquí: los datos llegan ordenados por tiempo y la tabla es enorme.
CREATE INDEX tag_reads_read_at_brin ON tag_reads USING brin (read_at) WITH (pages_per_range = 64);
CREATE INDEX tag_reads_epc_idx      ON tag_reads (epc, read_at DESC);
CREATE INDEX tag_reads_cycle_idx    ON tag_reads (inventory_cycle_id) WHERE inventory_cycle_id IS NOT NULL;
CREATE INDEX tag_reads_device_idx   ON tag_reads (device_id, read_at DESC);

-- =============================================================================
-- 7. MOVIMIENTOS DE STOCK (fuente de verdad, append-only)
-- =============================================================================

CREATE TABLE stock_movements (
    id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organization_id    BIGINT NOT NULL REFERENCES organizations(id),
    tag_id             BIGINT REFERENCES tags(id),           -- NULL en movimientos por SKU sin RFID
    product_variant_id BIGINT NOT NULL REFERENCES product_variants(id),
    movement_type      movement_type NOT NULL,
    quantity           INT NOT NULL DEFAULT 1
        CONSTRAINT stock_movements_qty_nonzero CHECK (quantity <> 0),
    -- Origen y destino
    from_location_id   BIGINT REFERENCES locations(id),
    from_zone_id       BIGINT REFERENCES zones(id),
    to_location_id     BIGINT REFERENCES locations(id),
    to_zone_id         BIGINT REFERENCES zones(id),
    -- Estado antes y después (auditoría del ciclo de vida del tag)
    state_before       tag_state,
    state_after        tag_state,
    -- Trazabilidad
    user_id            BIGINT REFERENCES users(id),
    device_id          BIGINT REFERENCES devices(id),
    -- Documento que originó el movimiento (polimórfico ligero)
    reference_type     VARCHAR(48),   -- 'receiving_order' | 'sale' | 'transfer' | 'inventory_cycle'
    reference_id       BIGINT,
    reason             VARCHAR(120),
    unit_cost          NUMERIC(12,4), -- valorización al momento del movimiento
    metadata           JSONB NOT NULL DEFAULT '{}'::jsonb,
    occurred_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX stock_movements_tag_idx      ON stock_movements (tag_id, occurred_at DESC);
CREATE INDEX stock_movements_variant_idx  ON stock_movements (product_variant_id, occurred_at DESC);
CREATE INDEX stock_movements_type_idx     ON stock_movements (movement_type, occurred_at DESC);
CREATE INDEX stock_movements_ref_idx      ON stock_movements (reference_type, reference_id);
CREATE INDEX stock_movements_location_idx ON stock_movements (to_location_id, occurred_at DESC);

-- Los movimientos no se modifican ni borran jamás.
CREATE OR REPLACE FUNCTION forbid_mutation() RETURNS TRIGGER AS $$
BEGIN
    RAISE EXCEPTION 'La tabla % es append-only; % no está permitido.', TG_TABLE_NAME, TG_OP;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER stock_movements_no_update
    BEFORE UPDATE OR DELETE ON stock_movements
    FOR EACH ROW EXECUTE FUNCTION forbid_mutation();

-- =============================================================================
-- 8. CICLOS DE INVENTARIO
-- =============================================================================

CREATE TABLE inventory_cycles (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organization_id BIGINT NOT NULL REFERENCES organizations(id),
    location_id     BIGINT NOT NULL REFERENCES locations(id),
    code            VARCHAR(32) NOT NULL,
    scope           cycle_scope NOT NULL DEFAULT 'total',
    scope_filter    JSONB NOT NULL DEFAULT '{}'::jsonb,  -- zonas, categorías, etc.
    status          cycle_status NOT NULL DEFAULT 'borrador',
    started_by      BIGINT REFERENCES users(id),
    started_at      TIMESTAMPTZ,
    closed_by       BIGINT REFERENCES users(id),
    closed_at       TIMESTAMPTZ,
    -- Fotografía del stock teórico al arrancar el ciclo
    expected_count  INT,
    counted_count   INT,
    found_count     INT,        -- esperados y encontrados
    missing_count   INT,        -- esperados y NO encontrados
    unexpected_count INT,       -- encontrados y NO esperados
    accuracy_pct    NUMERIC(6,3),
    notes           TEXT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT inventory_cycles_code_unique UNIQUE (organization_id, code)
);

CREATE INDEX inventory_cycles_location_idx ON inventory_cycles (location_id, started_at DESC);

-- Stock teórico congelado al inicio del ciclo (para conciliación reproducible)
CREATE TABLE inventory_cycle_expected (
    inventory_cycle_id BIGINT NOT NULL REFERENCES inventory_cycles(id) ON DELETE CASCADE,
    tag_id             BIGINT NOT NULL REFERENCES tags(id),
    product_variant_id BIGINT NOT NULL REFERENCES product_variants(id),
    zone_id            BIGINT REFERENCES zones(id),
    PRIMARY KEY (inventory_cycle_id, tag_id)
);

-- EPC efectivamente detectados durante el ciclo (deduplicados)
CREATE TABLE inventory_cycle_scans (
    inventory_cycle_id BIGINT NOT NULL REFERENCES inventory_cycles(id) ON DELETE CASCADE,
    epc                VARCHAR(48) NOT NULL,
    tag_id             BIGINT REFERENCES tags(id),
    zone_id            BIGINT REFERENCES zones(id),
    device_id          BIGINT REFERENCES devices(id),
    first_seen_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_seen_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    read_count         INT NOT NULL DEFAULT 1,
    max_rssi           NUMERIC(6,2),
    PRIMARY KEY (inventory_cycle_id, epc)
);

CREATE INDEX inventory_cycle_scans_tag_idx ON inventory_cycle_scans (tag_id);

-- Resultado de la conciliación, por variante
CREATE TABLE inventory_cycle_results (
    id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    inventory_cycle_id BIGINT NOT NULL REFERENCES inventory_cycles(id) ON DELETE CASCADE,
    product_variant_id BIGINT NOT NULL REFERENCES product_variants(id),
    expected_qty       INT NOT NULL DEFAULT 0,
    counted_qty        INT NOT NULL DEFAULT 0,
    difference_qty     INT GENERATED ALWAYS AS (counted_qty - expected_qty) STORED,
    value_difference   NUMERIC(14,4),
    CONSTRAINT inventory_cycle_results_unique UNIQUE (inventory_cycle_id, product_variant_id)
);

-- =============================================================================
-- 9. RECEPCIÓN, TRANSFERENCIAS Y VENTAS
-- =============================================================================

CREATE TABLE receiving_orders (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organization_id BIGINT NOT NULL REFERENCES organizations(id),
    supplier_id     BIGINT REFERENCES suppliers(id),
    location_id     BIGINT NOT NULL REFERENCES locations(id),
    code            VARCHAR(32) NOT NULL,
    external_ref    VARCHAR(64),         -- nº de factura / guía de remisión
    status          VARCHAR(24) NOT NULL DEFAULT 'pendiente',
    expected_at     TIMESTAMPTZ,
    received_at     TIMESTAMPTZ,
    received_by     BIGINT REFERENCES users(id),
    notes           TEXT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT receiving_orders_code_unique UNIQUE (organization_id, code)
);

CREATE TABLE receiving_order_lines (
    id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    receiving_order_id BIGINT NOT NULL REFERENCES receiving_orders(id) ON DELETE CASCADE,
    product_variant_id BIGINT NOT NULL REFERENCES product_variants(id),
    expected_qty       INT NOT NULL DEFAULT 0,
    received_qty       INT NOT NULL DEFAULT 0,
    unit_cost          NUMERIC(12,4),
    CONSTRAINT receiving_order_lines_unique UNIQUE (receiving_order_id, product_variant_id)
);

CREATE TABLE transfers (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organization_id     BIGINT NOT NULL REFERENCES organizations(id),
    code                VARCHAR(32) NOT NULL,
    from_location_id    BIGINT NOT NULL REFERENCES locations(id),
    to_location_id      BIGINT NOT NULL REFERENCES locations(id),
    status              VARCHAR(24) NOT NULL DEFAULT 'preparando',
    dispatched_at       TIMESTAMPTZ,
    received_at         TIMESTAMPTZ,
    dispatched_by       BIGINT REFERENCES users(id),
    received_by         BIGINT REFERENCES users(id),
    notes               TEXT,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT transfers_code_unique UNIQUE (organization_id, code),
    CONSTRAINT transfers_distinct_locations CHECK (from_location_id <> to_location_id)
);

CREATE TABLE transfer_tags (
    transfer_id BIGINT NOT NULL REFERENCES transfers(id) ON DELETE CASCADE,
    tag_id      BIGINT NOT NULL REFERENCES tags(id),
    dispatched  BOOLEAN NOT NULL DEFAULT FALSE,
    received    BOOLEAN NOT NULL DEFAULT FALSE,
    PRIMARY KEY (transfer_id, tag_id)
);

CREATE TABLE sale_transactions (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organization_id BIGINT NOT NULL REFERENCES organizations(id),
    location_id     BIGINT NOT NULL REFERENCES locations(id),
    code            VARCHAR(48) NOT NULL,
    external_ref    VARCHAR(64),          -- nº de comprobante del POS
    total_amount    NUMERIC(14,4),
    currency        CHAR(3) NOT NULL DEFAULT 'PEN',
    sold_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    user_id         BIGINT REFERENCES users(id),
    is_return       BOOLEAN NOT NULL DEFAULT FALSE,
    original_sale_id BIGINT REFERENCES sale_transactions(id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT sale_transactions_code_unique UNIQUE (organization_id, code)
);

CREATE TABLE sale_lines (
    id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    sale_transaction_id BIGINT NOT NULL REFERENCES sale_transactions(id) ON DELETE CASCADE,
    tag_id             BIGINT REFERENCES tags(id),      -- NULL si se vendió sin RFID
    product_variant_id BIGINT NOT NULL REFERENCES product_variants(id),
    quantity           INT NOT NULL DEFAULT 1,
    unit_price         NUMERIC(12,4),
    discount           NUMERIC(12,4) NOT NULL DEFAULT 0
);

CREATE INDEX sale_lines_tag_idx ON sale_lines (tag_id) WHERE tag_id IS NOT NULL;

-- =============================================================================
-- 10. ALERTAS Y MERMA
-- =============================================================================

CREATE TABLE alerts (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organization_id BIGINT NOT NULL REFERENCES organizations(id),
    location_id     BIGINT REFERENCES locations(id),
    kind            alert_kind NOT NULL,
    severity        SMALLINT NOT NULL DEFAULT 3
        CONSTRAINT alerts_severity_range CHECK (severity BETWEEN 1 AND 5),
    status          alert_status NOT NULL DEFAULT 'abierta',
    tag_id          BIGINT REFERENCES tags(id),
    device_id       BIGINT REFERENCES devices(id),
    title           VARCHAR(200) NOT NULL,
    detail          JSONB NOT NULL DEFAULT '{}'::jsonb,
    triggered_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    acknowledged_by BIGINT REFERENCES users(id),
    acknowledged_at TIMESTAMPTZ,
    resolved_at     TIMESTAMPTZ,
    resolution_note TEXT
);

CREATE INDEX alerts_open_idx ON alerts (organization_id, status, triggered_at DESC)
    WHERE status IN ('abierta', 'en_revision');
CREATE INDEX alerts_kind_idx ON alerts (kind, triggered_at DESC);

-- Eventos de portal (tránsitos detectados). Alimenta la alerta EAS.
CREATE TABLE portal_events (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    device_id     BIGINT NOT NULL REFERENCES devices(id),
    location_id   BIGINT NOT NULL REFERENCES locations(id),
    tag_id        BIGINT REFERENCES tags(id),
    epc           VARCHAR(48) NOT NULL,
    direction     VARCHAR(12),        -- 'salida' | 'entrada' | 'indeterminado'
    confidence    NUMERIC(4,3),
    was_sold      BOOLEAN,
    alarm_raised  BOOLEAN NOT NULL DEFAULT FALSE,
    occurred_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    evidence      JSONB NOT NULL DEFAULT '{}'::jsonb   -- secuencia de antenas y RSSI
);

CREATE INDEX portal_events_time_idx ON portal_events (location_id, occurred_at DESC);
CREATE INDEX portal_events_epc_idx  ON portal_events (epc, occurred_at DESC);

-- =============================================================================
-- 11. INSTANTÁNEAS DE STOCK (agregado materializado)
-- =============================================================================

CREATE TABLE stock_snapshots (
    id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organization_id    BIGINT NOT NULL REFERENCES organizations(id),
    location_id        BIGINT NOT NULL REFERENCES locations(id),
    zone_id            BIGINT REFERENCES zones(id),
    product_variant_id BIGINT NOT NULL REFERENCES product_variants(id),
    quantity           INT NOT NULL DEFAULT 0,
    snapshot_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    source             VARCHAR(24) NOT NULL DEFAULT 'derivado'  -- 'derivado' | 'ciclo'
);

CREATE INDEX stock_snapshots_lookup_idx
    ON stock_snapshots (location_id, product_variant_id, snapshot_at DESC);

-- =============================================================================
-- 12. AUDITORÍA
-- =============================================================================

CREATE TABLE audit_logs (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    organization_id BIGINT REFERENCES organizations(id),
    user_id         BIGINT REFERENCES users(id),
    device_id       BIGINT REFERENCES devices(id),
    action          VARCHAR(80) NOT NULL,
    subject_type    VARCHAR(64),
    subject_id      BIGINT,
    changes         JSONB NOT NULL DEFAULT '{}'::jsonb,
    ip_address      INET,
    user_agent      TEXT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX audit_logs_subject_idx ON audit_logs (subject_type, subject_id, created_at DESC);
CREATE INDEX audit_logs_user_idx    ON audit_logs (user_id, created_at DESC);

-- =============================================================================
-- 13. FUNCIONES DE APOYO
-- =============================================================================

-- Reserva atómica de un rango de seriales. Ver docs/04, §3.2.
CREATE OR REPLACE FUNCTION reserve_serial_range(
    p_variant_id BIGINT,
    p_count      INT
) RETURNS TABLE(serial_from BIGINT, serial_to BIGINT) AS $$
DECLARE
    v_from BIGINT;
    v_to   BIGINT;
BEGIN
    IF p_count <= 0 THEN
        RAISE EXCEPTION 'El número de seriales a reservar debe ser positivo.';
    END IF;

    INSERT INTO product_variant_counters (product_variant_id, last_serial)
    VALUES (p_variant_id, 0)
    ON CONFLICT (product_variant_id) DO NOTHING;

    UPDATE product_variant_counters
       SET last_serial = last_serial + p_count,
           updated_at  = now()
     WHERE product_variant_id = p_variant_id
    RETURNING last_serial - p_count + 1, last_serial
      INTO v_from, v_to;

    serial_from := v_from;
    serial_to   := v_to;
    RETURN NEXT;
END;
$$ LANGUAGE plpgsql;

-- Creación automática de la partición del mes siguiente.
CREATE OR REPLACE FUNCTION ensure_tag_reads_partition(p_month DATE)
RETURNS TEXT AS $$
DECLARE
    v_start DATE := date_trunc('month', p_month)::date;
    v_end   DATE := (date_trunc('month', p_month) + INTERVAL '1 month')::date;
    v_name  TEXT := format('tag_reads_%s', to_char(v_start, 'YYYY_MM'));
BEGIN
    IF EXISTS (SELECT 1 FROM pg_class WHERE relname = v_name) THEN
        RETURN format('La partición %s ya existe.', v_name);
    END IF;

    EXECUTE format(
        'CREATE TABLE %I PARTITION OF tag_reads FOR VALUES FROM (%L) TO (%L)',
        v_name, v_start, v_end
    );
    EXECUTE format(
        'CREATE INDEX %I ON %I USING brin (read_at) WITH (pages_per_range = 64)',
        v_name || '_brin', v_name
    );

    RETURN format('Partición %s creada.', v_name);
END;
$$ LANGUAGE plpgsql;

-- Purga de particiones antiguas (retención en caliente: 90 días).
CREATE OR REPLACE FUNCTION drop_old_tag_reads_partitions(p_keep_months INT DEFAULT 3)
RETURNS SETOF TEXT AS $$
DECLARE
    r RECORD;
    v_cutoff DATE := (date_trunc('month', now()) - (p_keep_months || ' months')::interval)::date;
BEGIN
    FOR r IN
        SELECT c.relname
          FROM pg_class c
          JOIN pg_inherits i ON i.inhrelid = c.oid
          JOIN pg_class p ON p.oid = i.inhparent
         WHERE p.relname = 'tag_reads'
           AND c.relname ~ '^tag_reads_\d{4}_\d{2}$'
           AND to_date(substring(c.relname from 11), 'YYYY_MM') < v_cutoff
    LOOP
        EXECUTE format('DROP TABLE IF EXISTS %I', r.relname);
        RETURN NEXT format('Partición %s eliminada.', r.relname);
    END LOOP;
END;
$$ LANGUAGE plpgsql;

-- Trigger genérico de updated_at.
CREATE OR REPLACE FUNCTION touch_updated_at() RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at := now();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$
DECLARE t TEXT;
BEGIN
    FOREACH t IN ARRAY ARRAY[
        'organizations','locations','zones','users','suppliers','categories',
        'products','product_variants','devices','tags','inventory_cycles',
        'receiving_orders'
    ] LOOP
        EXECUTE format(
            'CREATE TRIGGER %I BEFORE UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION touch_updated_at()',
            t || '_touch', t
        );
    END LOOP;
END $$;

COMMIT;
