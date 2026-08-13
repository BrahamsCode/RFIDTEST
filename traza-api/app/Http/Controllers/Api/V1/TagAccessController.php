<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateDevice;
use App\Http\Problem;
use App\Models\Device;
use App\Models\Tag;
use App\Services\TagAccessPasswordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Entrega al handheld la contraseña de escritura de UN EPC concreto.
 *
 * La clave maestra nunca sale del servidor. El handheld pide la contraseña
 * del tag que va a escribir, y solo cuando la necesita: así, comprometer un
 * handheld no compromete el resto del inventario. Ver `docs/12` §3.
 */
final class TagAccessController extends Controller
{
    public function __construct(
        private readonly TagAccessPasswordService $passwords,
    ) {}

    public function show(Request $request, string $epc): JsonResponse
    {
        $device = $request->attributes->get(AuthenticateDevice::ATTRIBUTE);

        if (! $device instanceof Device) {
            return Problem::forbidden('Solo un dispositivo dado de alta puede pedir contraseñas de tag.');
        }

        $epc = strtoupper($epc);

        // Un dispositivo solo obtiene contraseñas de tags de su organización.
        $belongs = Tag::query()
            ->where('organization_id', $device->organization_id)
            ->where('epc', $epc)
            ->exists();

        if (! $belongs) {
            return Problem::forbidden("El EPC {$epc} no pertenece a esta organización.");
        }

        try {
            return response()->json([
                'epc' => $epc,
                'access_password' => $this->passwords->for($epc),
                'kill_password' => $this->passwords->killPasswordFor($epc),
            ]);
        } catch (RuntimeException $e) {
            return Problem::make(503, 'Servicio no configurado', $e->getMessage());
        }
    }
}
