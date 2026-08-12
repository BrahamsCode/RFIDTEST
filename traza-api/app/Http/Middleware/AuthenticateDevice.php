<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica un dispositivo, no un usuario.
 *
 * El borde y el handheld no tienen sesión: presentan su código y un token
 * emitido al darlos de alta. El token se guarda hasheado, así que hay que
 * localizar el dispositivo por su código y luego verificar.
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

        $device = Device::query()->where('code', $code)->first();

        // Se comprueba el hash aunque el dispositivo no exista, para que el
        // tiempo de respuesta no revele qué códigos están dados de alta.
        $hash = $device?->api_token_hash ?? '$2y$12$'.str_repeat('.', 53);

        if (! Hash::check($token, $hash) || $device === null) {
            return $this->deny('Credenciales de dispositivo inválidas.');
        }

        if (! $device->isActive()) {
            return $this->deny("El dispositivo {$device->code} no está activo.", 403);
        }

        $request->attributes->set(self::ATTRIBUTE, $device);

        return $next($request);
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

    private function deny(string $message, int $status = 401): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}
