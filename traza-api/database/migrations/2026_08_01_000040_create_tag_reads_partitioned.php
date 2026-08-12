<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fuente: `sql/schema.sql` §6. Volumen alto y retención corta (ADR-002).
 *
 * Las particiones iniciales cubren agosto–octubre de 2026; a partir de ahí
 * las crea el comando mensual de rotación (tarea 8.2). La partición DEFAULT
 * es la red de seguridad si ese comando falla: si acumula filas de un mes
 * futuro, el `CREATE TABLE ... PARTITION OF` de ese mes fallará, por eso la
 * rotación debe ejecutarse el día 20 del mes anterior y alertar si la
 * default deja de estar vacía.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
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
                session_ref   UUID,
                inventory_cycle_id BIGINT,
                ingested_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
                PRIMARY KEY (id, read_at)
            ) PARTITION BY RANGE (read_at);

            CREATE TABLE tag_reads_2026_08 PARTITION OF tag_reads
                FOR VALUES FROM ('2026-08-01') TO ('2026-09-01');
            CREATE TABLE tag_reads_2026_09 PARTITION OF tag_reads
                FOR VALUES FROM ('2026-09-01') TO ('2026-10-01');
            CREATE TABLE tag_reads_2026_10 PARTITION OF tag_reads
                FOR VALUES FROM ('2026-10-01') TO ('2026-11-01');
            CREATE TABLE tag_reads_default PARTITION OF tag_reads DEFAULT;

            -- BRIN: los datos llegan ordenados por tiempo y la tabla es enorme.
            CREATE INDEX tag_reads_read_at_brin ON tag_reads USING brin (read_at) WITH (pages_per_range = 64);
            CREATE INDEX tag_reads_epc_idx      ON tag_reads (epc, read_at DESC);
            CREATE INDEX tag_reads_cycle_idx    ON tag_reads (inventory_cycle_id) WHERE inventory_cycle_id IS NOT NULL;
            CREATE INDEX tag_reads_device_idx   ON tag_reads (device_id, read_at DESC);
        SQL);
    }

    public function down(): void
    {
        // Basta con soltar la tabla madre: las particiones caen con ella.
        DB::unprepared('DROP TABLE IF EXISTS tag_reads CASCADE;');
    }
};
