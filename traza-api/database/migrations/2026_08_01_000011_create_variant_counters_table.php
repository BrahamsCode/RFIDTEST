<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fuente: `sql/schema.sql` §3. Contador de seriales por variante, base de la
 * reserva atómica de rangos EPC (`docs/04` §3.2).
 *
 * El tope de 274 877 906 943 es el máximo que caben en los 38 bits de serial
 * de un SGTIN-96.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE product_variant_counters (
                product_variant_id BIGINT PRIMARY KEY REFERENCES product_variants(id) ON DELETE CASCADE,
                last_serial        BIGINT NOT NULL DEFAULT 0
                    CONSTRAINT counters_serial_range CHECK (last_serial >= 0 AND last_serial <= 274877906943),
                updated_at         TIMESTAMPTZ NOT NULL DEFAULT now()
            );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS product_variant_counters CASCADE;');
    }
};
