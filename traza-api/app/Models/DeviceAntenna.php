<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DeviceAntenna extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'device_id', 'zone_id', 'port_number', 'label', 'mount_height_cm',
        'tilt_degrees', 'side', 'tx_power_dbm', 'rssi_threshold', 'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'port_number' => 'integer',
            'tx_power_dbm' => 'decimal:2',
            'rssi_threshold' => 'decimal:2',
            'is_enabled' => 'boolean',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }
}
