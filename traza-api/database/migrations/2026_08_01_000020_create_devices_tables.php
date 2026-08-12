<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Fuente: `sql/schema.sql` §4. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE devices (
                id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                organization_id BIGINT NOT NULL REFERENCES organizations(id),
                location_id     BIGINT REFERENCES locations(id),
                code            VARCHAR(32) NOT NULL,
                name            VARCHAR(120) NOT NULL,
                kind            device_kind NOT NULL,
                manufacturer    VARCHAR(80),
                model           VARCHAR(80),
                serial_number   VARCHAR(80),
                ip_address      INET,
                mac_address     MACADDR,
                firmware        VARCHAR(48),
                -- Perfil regulatorio: obligatorio. Ver docs/01, §3.
                regulatory_region VARCHAR(24) NOT NULL,
                status          device_status NOT NULL DEFAULT 'activo',
                api_token_hash  VARCHAR(255),
                last_seen_at    TIMESTAMPTZ,
                settings        JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT devices_code_unique UNIQUE (organization_id, code)
            );

            CREATE TABLE device_antennas (
                id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                device_id       BIGINT NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
                zone_id         BIGINT REFERENCES zones(id),
                port_number     SMALLINT NOT NULL,
                label           VARCHAR(80),
                mount_height_cm SMALLINT,
                tilt_degrees    SMALLINT,
                side            VARCHAR(16),
                tx_power_dbm    NUMERIC(5,2) NOT NULL DEFAULT 27.0,
                rssi_threshold  NUMERIC(6,2),
                is_enabled      BOOLEAN NOT NULL DEFAULT TRUE,
                CONSTRAINT device_antennas_port_unique UNIQUE (device_id, port_number)
            );

            CREATE TABLE read_profiles (
                id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                organization_id BIGINT NOT NULL REFERENCES organizations(id),
                code            VARCHAR(32) NOT NULL,
                name            VARCHAR(120) NOT NULL,
                session         SMALLINT NOT NULL DEFAULT 2
                    CONSTRAINT read_profiles_session_range CHECK (session BETWEEN 0 AND 3),
                target          CHAR(1) NOT NULL DEFAULT 'A'
                    CONSTRAINT read_profiles_target_valid CHECK (target IN ('A','B')),
                initial_q       SMALLINT NOT NULL DEFAULT 4
                    CONSTRAINT read_profiles_q_range CHECK (initial_q BETWEEN 0 AND 15),
                tx_power_dbm    NUMERIC(5,2) NOT NULL DEFAULT 27.0,
                dedup_window_ms INT NOT NULL DEFAULT 1000,
                min_read_count  SMALLINT NOT NULL DEFAULT 1,
                rssi_threshold  NUMERIC(6,2),
                read_tid        BOOLEAN NOT NULL DEFAULT FALSE,
                notes           TEXT,
                CONSTRAINT read_profiles_code_unique UNIQUE (organization_id, code)
            );

            CREATE TABLE device_health_beats (
                id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                device_id     BIGINT NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
                beat_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
                cpu_percent   NUMERIC(5,2),
                temperature_c NUMERIC(5,2),
                battery_pct   SMALLINT,
                reads_last_min INT,
                buffer_depth  INT,
                payload       JSONB NOT NULL DEFAULT '{}'::jsonb
            );

            CREATE INDEX device_health_beats_device_time_idx
                ON device_health_beats (device_id, beat_at DESC);

            CREATE TRIGGER devices_touch BEFORE UPDATE ON devices
                FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS device_health_beats CASCADE;
            DROP TABLE IF EXISTS read_profiles CASCADE;
            DROP TABLE IF EXISTS device_antennas CASCADE;
            DROP TABLE IF EXISTS devices CASCADE;
        SQL);
    }
};
