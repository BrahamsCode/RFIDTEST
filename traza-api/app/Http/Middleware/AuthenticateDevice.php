<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Devices\DeviceToken;
use App\Http\Problem;
use App\Models\Device;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica un dispositivo, no un usuario.
 *
 * El borde y el handheld no tienen sesión: presentan su código y un token
 * emitido al darlos de alta.
 *
 * **El dispositivo se localiza por el hash del token, no por el código.** El
 * código no identifica a nadie: `devices` es única por
 * `(organization_id, code)`, así que dos organizaciones pueden tener cada una
 * su `EDGE-01` con todo el derecho. Buscando por código y quedándose con el
 * primero —como se hacía antes— la organización que perdía el sorteo veía
 * rechazado su token perfectamente válido, y el fallo solo aparecía al
 * conectar la segunda tienda. El token sí identifica: es un secreto de 380
 * bits que solo tiene un dispositivo.
 */
final class AuthenticateDevice
{
    public const ATTRIBUTE = 'traza_device';

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->tokenFrom($request);
        $code = $this->codeFrom($request);

        if ($token === null || $code === null) {
            return $this->deny('Faltan las credenciales del dispositivo.');
        }

        $device = $this->resolve($token, $code);

        if ($device === null) {
            return $this->deny('Credenciales de dispositivo inválidas.');
        }

        // El código se sigue exigiendo aunque no sea lo que identifica: un
        // borde que presenta el token correcto con el código de otro está mal
        // configurado, y es mejor que falle ruidosamente ahora que atribuir
        // lecturas a la tienda equivocada durante meses.
        if ($device->code !== $code) {
            return $this->deny('Credenciales de dispositivo inválidas.');
        }

        if (! $device->isActive()) {
            return $this->deny("El dispositivo {$device->code} no está activo.", 403);
        }

        $request->attributes->set(self::ATTRIBUTE, $device);

        return $next($request);
    }

    /**
     * Busca por hash —una consulta indexada, sin ambigüedad entre
     * organizaciones— y solo si eso falla prueba el camino antiguo.
     */
    private function resolve(string $token, string $code): ?Device
    {
        $device = Device::query()
            ->where('api_token_hash', DeviceToken::hash($token))
            ->first();

        if ($device !== null) {
            return $device;
        }

        return $this->resolveLegacy($token, $code);
    }

    /**
     * Dispositivos dados de alta antes del cambio a SHA-256.
     *
     * Se verifican con bcrypt y se reescriben al vuelo, así que cada
     * dispositivo pasa por aquí una sola vez en su vida. Se filtra por hash
     * bcrypt en la consulta para que un token inventado no provoque ni una
     * comprobación cara: bcrypt tarda ~231 ms y sería una forma cómoda de
     * tumbar la ingesta a base de credenciales basura.
     */
    private function resolveLegacy(string $token, string $code): ?Device
    {
        $candidates = Device::query()
            ->where('code', $code)
            ->where('api_token_hash', 'like', '$2%')
            ->get();

        foreach ($candidates as $device) {
            if (! DeviceToken::matches($token, $device->api_token_hash)) {
                continue;
            }

            $device->forceFill(['api_token_hash' => DeviceToken::hash($token)])->saveQuietly();

            return $device;
        }

        return null;
    }

    private function tokenFrom(Request $request): ?string
    {
        $header = $request->header('X-Device-Token') ?? $request->bearerToken();

        return is_string($header) && $header !== '' ? $header : null;
    }

    private function codeFrom(Request $request): ?string
    {
        $code = $request->header('X-Device-Code') ?? $request->input('device_code');

        return is_string($code) && $code !== '' ? $code : null;
    }

    /** RFC 7807, como el resto de la API (`docs/06` §6). */
    private function deny(string $message, int $status = 401): JsonResponse
    {
        return Problem::make(
            $status,
            $status === 403 ? 'Dispositivo no activo' : 'Credenciales de dispositivo inválidas',
            $message,
            'https://traza.pe/problems/device-auth',
        );
    }
}
