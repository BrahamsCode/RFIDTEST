<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Fuente: `sql/schema.sql` §2. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
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

            CREATE TRIGGER locations_touch BEFORE UPDATE ON locations
                FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
            CREATE TRIGGER zones_touch BEFORE UPDATE ON zones
                FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS zones CASCADE;
            DROP TABLE IF EXISTS locations CASCADE;
        SQL);
    }
};
