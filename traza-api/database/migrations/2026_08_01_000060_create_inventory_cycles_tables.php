<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fuente: `sql/schema.sql` §8.
 *
 * `inventory_cycle_expected` congela el stock teórico al arrancar el ciclo:
 * sin esa fotografía, una venta a mitad del conteo cambiaría la lista de
 * esperados y la conciliación dejaría de ser reproducible.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE inventory_cycles (
                id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                organization_id BIGINT NOT NULL REFERENCES organizations(id),
                location_id     BIGINT NOT NULL REFERENCES locations(id),
                code            VARCHAR(32) NOT NULL,
                scope           cycle_scope NOT NULL DEFAULT 'total',
                scope_filter    JSONB NOT NULL DEFAULT '{}'::jsonb,
                status          cycle_status NOT NULL DEFAULT 'borrador',
                started_by      BIGINT REFERENCES users(id),
                started_at      TIMESTAMPTZ,
                closed_by       BIGINT REFERENCES users(id),
                closed_at       TIMESTAMPTZ,
                expected_count  INT,
                counted_count   INT,
                found_count     INT,
                missing_count   INT,
                unexpected_count INT,
                accuracy_pct    NUMERIC(6,3),
                notes           TEXT,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT inventory_cycles_code_unique UNIQUE (organization_id, code)
            );

            CREATE INDEX inventory_cycles_location_idx ON inventory_cycles (location_id, started_at DESC);

            CREATE TABLE inventory_cycle_expected (
                inventory_cycle_id BIGINT NOT NULL REFERENCES inventory_cycles(id) ON DELETE CASCADE,
                tag_id             BIGINT NOT NULL REFERENCES tags(id),
                product_variant_id BIGINT NOT NULL REFERENCES product_variants(id),
                zone_id            BIGINT REFERENCES zones(id),
                PRIMARY KEY (inventory_cycle_id, tag_id)
            );

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

            CREATE TRIGGER inventory_cycles_touch BEFORE UPDATE ON inventory_cycles
                FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS inventory_cycle_results CASCADE;
            DROP TABLE IF EXISTS inventory_cycle_scans CASCADE;
            DROP TABLE IF EXISTS inventory_cycle_expected CASCADE;
            DROP TABLE IF EXISTS inventory_cycles CASCADE;
        SQL);
    }
};
