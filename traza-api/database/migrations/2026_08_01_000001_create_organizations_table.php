<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fuente: `sql/schema.sql` §1 y §2.
 *
 * El DDL va en SQL crudo y no por el constructor de esquemas de Laravel
 * porque este no sabe emitir `GENERATED ALWAYS AS IDENTITY`, tipos ENUM,
 * tablas particionadas ni columnas generadas. El criterio de aceptación de
 * la tarea 1.1 exige reproducir `sql/schema.sql` exactamente, y solo el DDL
 * literal lo garantiza.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE EXTENSION IF NOT EXISTS "pgcrypto";
            CREATE EXTENSION IF NOT EXISTS "btree_gin";
            CREATE EXTENSION IF NOT EXISTS "pg_trgm";

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
                'tarado',
                'recepcion',
                'venta',
                'devolucion_cliente',
                'devolucion_prov',
                'transferencia_out',
                'transferencia_in',
                'ajuste_positivo',
                'ajuste_negativo',
                'merma',
                'dano',
                'cambio_zona',
                'reetiquetado',
                'anulacion'
            );

            CREATE TYPE location_type AS ENUM ('tienda', 'almacen', 'taller', 'transito', 'virtual');

            CREATE TYPE zone_kind AS ENUM (
                'sala',
                'trastienda',
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
                'salida_no_vendida',
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

            CREATE TABLE organizations (
                id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                name            VARCHAR(160) NOT NULL,
                tax_id          VARCHAR(20),
                gs1_company_prefix VARCHAR(12),
                default_epc_scheme epc_scheme NOT NULL DEFAULT 'gid-96',
                epc_filter_mask VARCHAR(64),
                timezone        VARCHAR(64) NOT NULL DEFAULT 'America/Lima',
                settings        JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
            );

            CREATE OR REPLACE FUNCTION touch_updated_at() RETURNS TRIGGER AS $$
            BEGIN
                NEW.updated_at := now();
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER organizations_touch BEFORE UPDATE ON organizations
                FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS organizations CASCADE;
            DROP FUNCTION IF EXISTS touch_updated_at() CASCADE;
            DROP TYPE IF EXISTS epc_scheme;
            DROP TYPE IF EXISTS alert_status;
            DROP TYPE IF EXISTS alert_kind;
            DROP TYPE IF EXISTS cycle_scope;
            DROP TYPE IF EXISTS cycle_status;
            DROP TYPE IF EXISTS device_status;
            DROP TYPE IF EXISTS device_kind;
            DROP TYPE IF EXISTS zone_kind;
            DROP TYPE IF EXISTS location_type;
            DROP TYPE IF EXISTS movement_type;
            DROP TYPE IF EXISTS tag_state;
        SQL);
    }
};
