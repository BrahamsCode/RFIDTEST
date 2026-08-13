<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Device;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Alta de un handheld escaneando un QR. Ver `docs/09` §10.
 *
 * El token de alta es de un solo uso y caduca a los 15 minutos. Lo primero
 * porque el QR acaba fotografiado en el móvil de alguien o impreso en un
 * papel encima del mostrador; lo segundo porque un token de alta que no
 * caduca es una puerta abierta permanente al API.
 *
 * Vive en la caché con TTL nativo y no en una tabla propia: el esquema de
 * `sql/schema.sql` no tiene sitio para esto, y añadirle una tabla es una
 * decisión de modelo de datos que no toca tomar aquí (ver `tasks/TASKS.md`).
 * La consecuencia se asume: si se vacía la caché, los QR emitidos y aún sin
 * canjear dejan de valer y hay que volver a generarlos. Para una ventana de
 * 15 minutos es un precio pequeño, y falla cerrado.
 */
final class DeviceEnrollmentService
{
    public const TTL_MINUTES = 15;

    private const PREFIX = 'device-enrollment:';

    /**
     * Emite el token y devuelve la carga que va dentro del QR.
     *
     * @return array{payload: array<string, mixed>, expires_at: Carbon}
     */
    public function issue(Device $device, ?string $baseUrl = null): array
    {
        // Un token por dispositivo: emitir uno nuevo invalida el anterior,
        // que es lo que espera quien vuelve a la pantalla porque el primer
        // QR se perdió.
        $this->revoke($device);

        $token = Str::random(64);
        $expiresAt = now()->addMinutes(self::TTL_MINUTES);

        Cache::put(
            self::PREFIX.$device->id,
            ['hash' => hash('sha256', $token), 'device_code' => $device->code],
            $expiresAt,
        );

        return [
            'payload' => [
                'v' => 1,
                'url' => rtrim($baseUrl ?? (string) config('app.url'), '/'),
                'device_code' => $device->code,
                'enrollment_token' => $token,
                'location_id' => $device->location_id,
            ],
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Canjea el token por el token permanente del dispositivo.
     *
     * Devuelve null si el token no vale: caducado, ya usado, de otro
     * dispositivo o simplemente inventado. Un solo `null` para todos los
     * casos a propósito — distinguirlos le diría a quien prueba a ciegas
     * cuál de sus suposiciones era la buena.
     */
    public function redeem(string $deviceCode, string $token): ?string
    {
        $device = Device::query()->where('code', $deviceCode)->first();

        if ($device === null) {
            return null;
        }

        $key = self::PREFIX.$device->id;
        $stored = Cache::get($key);

        if (! is_array($stored) || ! isset($stored['hash'])) {
            return null;
        }

        if (! hash_equals((string) $stored['hash'], hash('sha256', $token))) {
            return null;
        }

        // Un solo uso: se borra antes de emitir nada. Si algo falla después,
        // el QR ya no vale y hay que generar otro — preferible a que dos
        // equipos se den de alta con el mismo código.
        Cache::forget($key);

        $apiToken = Str::random(64);

        $device->forceFill([
            'api_token_hash' => Hash::make($apiToken),
            'status' => 'activo',
            'last_seen_at' => now(),
        ])->save();

        return $apiToken;
    }

    public function revoke(Device $device): void
    {
        Cache::forget(self::PREFIX.$device->id);
    }

    public function hasPendingEnrollment(Device $device): bool
    {
        return Cache::has(self::PREFIX.$device->id);
    }
}
