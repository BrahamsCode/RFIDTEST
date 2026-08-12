<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fuente: `sql/schema.sql` §9 (ventas).
 *
 * `sale_lines.tag_id` es nulable a propósito: una venta puede registrarse sin
 * RFID (prenda sin tarar, tag arrancado). El índice parcial solo cubre las
 * que sí lo llevan, que son las que consulta el portal antihurto.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE sale_transactions (
                id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                organization_id BIGINT NOT NULL REFERENCES organizations(id),
                location_id     BIGINT NOT NULL REFERENCES locations(id),
                code            VARCHAR(48) NOT NULL,
                external_ref    VARCHAR(64),
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
                tag_id             BIGINT REFERENCES tags(id),
                product_variant_id BIGINT NOT NULL REFERENCES product_variants(id),
                quantity           INT NOT NULL DEFAULT 1,
                unit_price         NUMERIC(12,4),
                discount           NUMERIC(12,4) NOT NULL DEFAULT 0
            );

            CREATE INDEX sale_lines_tag_idx ON sale_lines (tag_id) WHERE tag_id IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS sale_lines CASCADE;
            DROP TABLE IF EXISTS sale_transactions CASCADE;
        SQL);
    }
};
