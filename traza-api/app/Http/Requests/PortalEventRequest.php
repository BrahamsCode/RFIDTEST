<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Middleware\AuthenticateDevice;
use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Camino HTTP de respaldo del portal. El camino normal es MQTT (`docs/07`
 * §6); esto existe para las tiendas sin broker propio y para diagnosticar
 * un portal desde la consola.
 */
final class PortalEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->device() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'device_code' => ['sometimes', 'string', 'max:32'],
            'epc' => ['required', 'string', 'regex:/^[0-9A-Fa-f]{16,48}$/'],
            'direction' => ['sometimes', 'in:salida,entrada,indeterminado'],
            'confidence' => ['sometimes', 'numeric', 'between:0,1'],
            'occurred_at' => ['sometimes', 'date'],
            'evidence' => ['sometimes', 'array'],
        ];
    }

    public function device(): ?Device
    {
        $device = $this->attributes->get(AuthenticateDevice::ATTRIBUTE);

        return $device instanceof Device ? $device : null;
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'epc' => (string) $this->string('epc'),
            'direction' => (string) ($this->input('direction') ?? 'indeterminado'),
            'confidence' => (float) ($this->input('confidence') ?? 0.0),
            'occurredAt' => $this->input('occurred_at'),
            'evidence' => (array) ($this->input('evidence') ?? []),
        ];
    }
}
