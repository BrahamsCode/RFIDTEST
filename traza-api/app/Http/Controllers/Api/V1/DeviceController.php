<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\Paginates;
use App\Http\Controllers\Controller;
use App\Http\Problem;
use App\Models\Device;
use App\Services\DeviceEnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeviceController extends Controller
{
    use Paginates;

    public function __construct(
        private readonly DeviceEnrollmentService $enrollment,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Device::query()
            ->where('organization_id', $user->organization_id)
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->string('kind')))
            ->when($request->filled('location'), fn ($q) => $q->where('location_id', $request->integer('location')))
            ->unless(
                $user->hasPermission('location.all'),
                fn ($q) => $q->where('location_id', $user->default_location_id),
            )
            ->orderBy('code');

        return response()->json($this->paginated($query, $request, fn (Device $d) => [
            'id' => $d->id,
            'code' => $d->code,
            'name' => $d->name,
            'kind' => $d->kind,
            'status' => $d->status,
            'location_id' => $d->location_id,
            'firmware' => $d->firmware,
            'last_seen_at' => $d->last_seen_at,
            // Un lector sin latido reciente está muerto aunque figure activo.
            'is_online' => $d->last_seen_at !== null && $d->last_seen_at->gt(now()->subMinutes(5)),
            'has_pending_enrollment' => $this->enrollment->hasPendingEnrollment($d),
        ]));
    }

    /**
     * Genera el QR de alta de `docs/09` §10. Devuelve la carga en JSON; el
     * QR lo dibuja la web, que es donde está la pantalla.
     */
    public function enroll(Request $request, Device $device): JsonResponse
    {
        $user = $request->user();

        // `device.manage` es del técnico y del administrador. Dar de alta un
        // equipo es entregarle credenciales de ingesta: no es una acción de
        // tienda.
        if (! $user->hasPermission('device.manage')) {
            return Problem::forbidden('No tienes permiso para dar de alta dispositivos.');
        }

        if ($device->organization_id !== $user->organization_id) {
            return Problem::forbidden('Ese dispositivo no es de tu organización.');
        }

        if ($device->status === 'baja') {
            return Problem::conflict("El dispositivo {$device->code} está dado de baja.");
        }

        $issued = $this->enrollment->issue($device, $request->input('base_url'));

        return response()->json([
            'device_code' => $device->code,
            'expires_at' => $issued['expires_at'],
            'expires_in_minutes' => DeviceEnrollmentService::TTL_MINUTES,
            // La web codifica esto tal cual en el QR.
            'qr_payload' => $issued['payload'],
        ], 201);
    }

    public function revokeEnrollment(Request $request, Device $device): JsonResponse
    {
        if (! $request->user()->hasPermission('device.manage')) {
            return Problem::forbidden('No tienes permiso para dar de alta dispositivos.');
        }

        $this->enrollment->revoke($device);

        return response()->json(['device_code' => $device->code, 'status' => 'revocado']);
    }

    /**
     * Canje del QR. **Sin sesión y sin token de dispositivo**: es la única
     * ruta del API que puede llamarse sin credenciales, porque justamente
     * sirve para obtenerlas. Lo que la protege es que el token de alta es de
     * un solo uso y dura 15 minutos.
     */
    public function redeem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_code' => ['required', 'string', 'max:32'],
            'enrollment_token' => ['required', 'string', 'max:128'],
        ]);

        $apiToken = $this->enrollment->redeem($data['device_code'], $data['enrollment_token']);

        if ($apiToken === null) {
            // Una sola respuesta para todos los motivos: distinguirlos le
            // diría a quien prueba a ciegas cuál de sus suposiciones acertó.
            return Problem::make(
                422,
                'Alta no válida',
                'El código de alta no es válido o ha caducado. Genera uno nuevo desde la web.',
                'https://traza.pe/problems/enrollment-invalid',
            );
        }

        $device = Device::query()->where('code', $data['device_code'])->firstOrFail();

        return response()->json([
            'device_code' => $device->code,
            'device_name' => $device->name,
            'location_id' => $device->location_id,
            'organization_id' => $device->organization_id,
            // Se devuelve una sola vez. En el dispositivo va a
            // EncryptedSharedPreferences.
            'api_token' => $apiToken,
        ], 201);
    }
}
