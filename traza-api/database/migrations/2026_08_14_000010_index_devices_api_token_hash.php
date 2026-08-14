<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índice único sobre `devices.api_token_hash`.
 *
 * `AuthenticateDevice` localiza el dispositivo **por el hash de su token** y
 * no por su código, porque el código no identifica a nadie: `devices` es
 * única por `(organization_id, code)` y dos organizaciones pueden tener cada
 * una su `EDGE-01`. Esa consulta corre en cada petición de ingesta —una por
 * borde y por segundo—, así que necesita índice; sin él sería un recorrido
 * secuencial de la tabla.
 *
 * Único y no simple porque además expresa un invariante que conviene que
 * imponga la base: dos dispositivos no pueden compartir token. En PostgreSQL
 * un índice único admite varios NULL, así que los dispositivos aún sin dar de
 * alta —que tienen el hash a NULL— conviven sin problema.
 *
 * Se refleja también en `sql/schema.sql`, que es el documento de referencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(
            'CREATE UNIQUE INDEX devices_api_token_hash_unique ON devices (api_token_hash);',
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP INDEX IF EXISTS devices_api_token_hash_unique;');
    }
};
