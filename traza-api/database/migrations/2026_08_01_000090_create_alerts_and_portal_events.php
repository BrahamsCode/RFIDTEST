<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fuente: `sql/schema.sql` §10.
 *
 * `portal_events.evidence` guarda la secuencia de antenas y RSSI del cruce.
 * La clasificación de dirección es heurística y falla en trazas ambiguas, así
 * que conservar la evidencia es lo que permite revisar una alarma discutida.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE alerts (
                id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                organization_id BIGINT NOT NULL REFERENCES organizations(id),
                location_id     BIGINT REFERENCES locations(id),
                kind            alert_kind NOT NULL,
                severity        SMALLINT NOT NULL DEFAULT 3
                    CONSTRAINT alerts_severity_range CHECK (severity BETWEEN 1 AND 5),
                status          alert_status NOT NULL DEFAULT 'abierta',
                tag_id          BIGINT REFERENCES tags(id),
                device_id       BIGINT REFERENCES devices(id),
                title           VARCHAR(200) NOT NULL,
                detail          JSONB NOT NULL DEFAULT '{}'::jsonb,
                triggered_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
                acknowledged_by BIGINT REFERENCES users(id),
                acknowledged_at TIMESTAMPTZ,
                resolved_at     TIMESTAMPTZ,
                resolution_note TEXT
            );

            CREATE INDEX alerts_open_idx ON alerts (organization_id, status, triggered_at DESC)
                WHERE status IN ('abierta', 'en_revision');
            CREATE INDEX alerts_kind_idx ON alerts (kind, triggered_at DESC);

            CREATE TABLE portal_events (
                id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                device_id     BIGINT NOT NULL REFERENCES devices(id),
                location_id   BIGINT NOT NULL REFERENCES locations(id),
                tag_id        BIGINT REFERENCES tags(id),
                epc           VARCHAR(48) NOT NULL,
                direction     VARCHAR(12),
                confidence    NUMERIC(4,3),
                was_sold      BOOLEAN,
                alarm_raised  BOOLEAN NOT NULL DEFAULT FALSE,
                occurred_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
                evidence      JSONB NOT NULL DEFAULT '{}'::jsonb
            );

            CREATE INDEX portal_events_time_idx ON portal_events (location_id, occurred_at DESC);
            CREATE INDEX portal_events_epc_idx  ON portal_events (epc, occurred_at DESC);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS portal_events CASCADE;
            DROP TABLE IF EXISTS alerts CASCADE;
        SQL);
    }
};
