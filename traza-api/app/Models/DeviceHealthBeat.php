<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DeviceHealthBeat extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'device_id', 'beat_at', 'cpu_percent', 'temperature_c', 'battery_pct',
        'reads_last_min', 'buffer_depth', 'payload',
    ];

    protected function casts(): array
    {
        return [
            'beat_at' => 'datetime',
            'cpu_percent' => 'decimal:2',
            'temperature_c' => 'decimal:2',
            'battery_pct' => 'integer',
            'reads_last_min' => 'integer',
            'buffer_depth' => 'integer',
            'payload' => 'array',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
