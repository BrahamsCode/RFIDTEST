<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Middleware\AuthenticateDevice;
use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;

final class RegisterScansRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->device() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxBatch = (int) config('traza.ingest.max_batch', 1000);

        return [
            'device_code' => ['sometimes', 'string', 'max:32'],
            'scans' => ['required', 'array', 'min:1', "max:{$maxBatch}"],
            'scans.*.epc' => ['required', 'string', 'regex:/^[0-9A-Fa-f]{8,48}$/'],
            'scans.*.zone_id' => ['nullable', 'integer', 'min:1'],
            'scans.*.rssi' => ['nullable', 'numeric', 'between:-120,20'],
            'scans.*.read_at' => ['nullable', 'date'],
            'scans.*.read_count' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function device(): ?Device
    {
        $device = $this->attributes->get(AuthenticateDevice::ATTRIBUTE);

        return $device instanceof Device ? $device : null;
    }
}
