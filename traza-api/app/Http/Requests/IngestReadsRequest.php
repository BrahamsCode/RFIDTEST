<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Middleware\AuthenticateDevice;
use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;

final class IngestReadsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Lo resuelve el middleware AuthenticateDevice.
        return $this->device() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxBatch = (int) config('traza.ingest.max_batch', 1000);

        return [
            'device_code' => ['sometimes', 'string', 'max:32'],
            'batch_id' => ['required', 'uuid'],
            'session_ref' => ['nullable', 'uuid'],
            'inventory_cycle_id' => ['nullable', 'integer', 'min:1'],

            'reads' => ['required', 'array', 'min:1', "max:{$maxBatch}"],
            // El EPC se valida como hexadecimal aquí y contra la máscara en el
            // servicio: son dos comprobaciones distintas.
            'reads.*.epc' => ['required', 'string', 'regex:/^[0-9A-Fa-f]{8,48}$/'],
            'reads.*.tid' => ['nullable', 'string', 'regex:/^[0-9A-Fa-f]{8,48}$/'],
            'reads.*.antenna' => ['nullable', 'integer', 'min:1', 'max:32'],
            'reads.*.rssi' => ['nullable', 'numeric', 'between:-120,20'],
            'reads.*.phase_angle' => ['nullable', 'numeric'],
            'reads.*.doppler_hz' => ['nullable', 'numeric'],
            'reads.*.read_at' => ['required', 'date'],
            'reads.*.read_count' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reads.max' => 'El lote supera el máximo de :max lecturas por envío.',
            'reads.*.epc.regex' => 'Un EPC debe ser hexadecimal de 8 a 48 caracteres.',
        ];
    }

    public function device(): ?Device
    {
        $device = $this->attributes->get(AuthenticateDevice::ATTRIBUTE);

        return $device instanceof Device ? $device : null;
    }
}
