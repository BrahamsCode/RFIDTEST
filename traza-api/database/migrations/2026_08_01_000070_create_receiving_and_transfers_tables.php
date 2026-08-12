<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Fuente: `sql/schema.sql` §9 (recepción y transferencias). */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE receiving_orders (
                id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                organization_id BIGINT NOT NULL REFERENCES organizations(id),
                supplier_id     BIGINT REFERENCES suppliers(id),
                location_id     BIGINT NOT NULL REFERENCES locations(id),
                code            VARCHAR(32) NOT NULL,
                external_ref    VARCHAR(64),
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

            CREATE TRIGGER receiving_orders_touch BEFORE UPDATE ON receiving_orders
                FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS transfer_tags CASCADE;
            DROP TABLE IF EXISTS transfers CASCADE;
            DROP TABLE IF EXISTS receiving_order_lines CASCADE;
            DROP TABLE IF EXISTS receiving_orders CASCADE;
        SQL);
    }
};
