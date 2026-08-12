<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fuente: `sql/schema.sql` §5.
 *
 * `tags` guarda el estado espacial desnormalizado a propósito (ADR-005): es
 * una proyección de `stock_movements`, no una segunda fuente de verdad. Solo
 * `StockMovementService` puede mutarla.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE tag_batches (
                id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                organization_id BIGINT NOT NULL REFERENCES organizations(id),
                product_variant_id BIGINT REFERENCES product_variants(id),
                device_id       BIGINT REFERENCES devices(id),
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
                tid                VARCHAR(48),
                product_variant_id BIGINT REFERENCES product_variants(id),
                tag_batch_id       BIGINT REFERENCES tag_batches(id),
                state              tag_state NOT NULL DEFAULT 'creado',
                current_location_id BIGINT REFERENCES locations(id),
                current_zone_id     BIGINT REFERENCES zones(id),
                commissioned_at    TIMESTAMPTZ,
                first_seen_at      TIMESTAMPTZ,
                last_seen_at       TIMESTAMPTZ,
                sold_at            TIMESTAMPTZ,
                missed_cycles      SMALLINT NOT NULL DEFAULT 0,
                replaces_tag_id    BIGINT REFERENCES tags(id),
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

            CREATE TABLE tag_replacements (
                id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                old_tag_id    BIGINT NOT NULL REFERENCES tags(id),
                new_tag_id    BIGINT NOT NULL REFERENCES tags(id),
                reason        VARCHAR(64) NOT NULL,
                performed_by  BIGINT REFERENCES users(id),
                performed_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT tag_replacements_distinct CHECK (old_tag_id <> new_tag_id),
                CONSTRAINT tag_replacements_new_unique UNIQUE (new_tag_id)
            );

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

            CREATE TRIGGER tags_touch BEFORE UPDATE ON tags
                FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS unknown_epcs CASCADE;
            DROP TABLE IF EXISTS tag_replacements CASCADE;
            DROP TABLE IF EXISTS tags CASCADE;
            DROP TABLE IF EXISTS tag_batches CASCADE;
        SQL);
    }
};
