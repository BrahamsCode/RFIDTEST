<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Middleware\AuthenticateDevice;
use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;

final class HeartbeatRequest extends FormRequest
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
            'cpu_percent' => ['nullable', 'numeric', 'between:0,100'],
            'temperature_c' => ['nullable', 'numeric', 'between:-40,150'],
            'battery_pct' => ['nullable', 'integer', 'between:0,100'],
            'reads_last_min' => ['nullable', 'integer', 'min:0'],
            'buffer_depth' => ['nullable', 'integer', 'min:0'],
            'uptime_s' => ['nullable', 'integer', 'min:0'],
            'version' => ['nullable', 'string', 'max:48'],
            'readers' => ['nullable', 'array'],
        ];
    }

    public function device(): ?Device
    {
        $device = $this->attributes->get(AuthenticateDevice::ATTRIBUTE);

        return $device instanceof Device ? $device : null;
    }
}
