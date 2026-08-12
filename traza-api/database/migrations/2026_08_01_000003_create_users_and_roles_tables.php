<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fuente: `sql/schema.sql` §2.
 *
 * Sustituye a la migración `users` que trae Laravel de fábrica: el esquema
 * de TRAZA lleva `organization_id`, `default_location_id` y `is_active`, y
 * no usa `email_verified_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE users (
                id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                organization_id BIGINT NOT NULL REFERENCES organizations(id),
                name            VARCHAR(160) NOT NULL,
                email           VARCHAR(190) NOT NULL UNIQUE,
                password        VARCHAR(255) NOT NULL,
                default_location_id BIGINT REFERENCES locations(id),
                is_active       BOOLEAN NOT NULL DEFAULT TRUE,
                last_login_at   TIMESTAMPTZ,
                remember_token  VARCHAR(100),
                created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
            );

            CREATE TABLE roles (
                id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                code        VARCHAR(48) NOT NULL UNIQUE,
                name        VARCHAR(120) NOT NULL,
                permissions JSONB NOT NULL DEFAULT '[]'::jsonb
            );

            CREATE TABLE role_user (
                user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                role_id BIGINT NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
                PRIMARY KEY (user_id, role_id)
            );

            CREATE TRIGGER users_touch BEFORE UPDATE ON users
                FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS role_user CASCADE;
            DROP TABLE IF EXISTS roles CASCADE;
            DROP TABLE IF EXISTS users CASCADE;
        SQL);
    }
};
