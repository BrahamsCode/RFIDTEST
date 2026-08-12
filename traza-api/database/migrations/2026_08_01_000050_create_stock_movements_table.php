<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fuente: `sql/schema.sql` §7. Fuente de verdad del stock, append-only.
 *
 * El trigger no es decorativo: es la garantía a nivel de base de datos de que
 * el histórico no se puede reescribir, ni siquiera desde una consola SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE stock_movements (
                id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                organization_id    BIGINT NOT NULL REFERENCES organizations(id),
                tag_id             BIGINT REFERENCES tags(id),
                product_variant_id BIGINT NOT NULL REFERENCES product_variants(id),
                movement_type      movement_type NOT NULL,
                quantity           INT NOT NULL DEFAULT 1
                    CONSTRAINT stock_movements_qty_nonzero CHECK (quantity <> 0),
                from_location_id   BIGINT REFERENCES locations(id),
                from_zone_id       BIGINT REFERENCES zones(id),
                to_location_id     BIGINT REFERENCES locations(id),
                to_zone_id         BIGINT REFERENCES zones(id),
                state_before       tag_state,
                state_after        tag_state,
                user_id            BIGINT REFERENCES users(id),
                device_id          BIGINT REFERENCES devices(id),
                reference_type     VARCHAR(48),
                reference_id       BIGINT,
                reason             VARCHAR(120),
                unit_cost          NUMERIC(12,4),
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
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS stock_movements CASCADE;
            DROP FUNCTION IF EXISTS forbid_mutation() CASCADE;
        SQL);
    }
};
