<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Fuente: `sql/schema.sql` §11 y §12. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE stock_snapshots (
                id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                organization_id    BIGINT NOT NULL REFERENCES organizations(id),
                location_id        BIGINT NOT NULL REFERENCES locations(id),
                zone_id            BIGINT REFERENCES zones(id),
                product_variant_id BIGINT NOT NULL REFERENCES product_variants(id),
                quantity           INT NOT NULL DEFAULT 0,
                snapshot_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
                source             VARCHAR(24) NOT NULL DEFAULT 'derivado'
            );

            CREATE INDEX stock_snapshots_lookup_idx
                ON stock_snapshots (location_id, product_variant_id, snapshot_at DESC);

            CREATE TABLE audit_logs (
                id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                organization_id BIGINT REFERENCES organizations(id),
                user_id         BIGINT REFERENCES users(id),
                device_id       BIGINT REFERENCES devices(id),
                action          VARCHAR(80) NOT NULL,
                subject_type    VARCHAR(64),
                subject_id      BIGINT,
                changes         JSONB NOT NULL DEFAULT '{}'::jsonb,
                ip_address      INET,
                user_agent      TEXT,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
            );

            CREATE INDEX audit_logs_subject_idx ON audit_logs (subject_type, subject_id, created_at DESC);
            CREATE INDEX audit_logs_user_idx    ON audit_logs (user_id, created_at DESC);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS audit_logs CASCADE;
            DROP TABLE IF EXISTS stock_snapshots CASCADE;
        SQL);
    }
};
