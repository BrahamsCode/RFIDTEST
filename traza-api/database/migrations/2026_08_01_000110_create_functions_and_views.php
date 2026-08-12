<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fuente: `sql/schema.sql` §13.
 *
 * `reserve_serial_range` es atómica por diseño: el UPDATE toma un bloqueo de
 * fila sobre el contador, así que dos procesos que reserven a la vez obtienen
 * rangos disjuntos. Es lo que impide emitir dos etiquetas con el mismo EPC.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
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
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS drop_old_tag_reads_partitions(INT);
            DROP FUNCTION IF EXISTS ensure_tag_reads_partition(DATE);
            DROP FUNCTION IF EXISTS reserve_serial_range(BIGINT, INT);
        SQL);
    }
};
