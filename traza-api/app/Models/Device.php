<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeviceKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Device extends Model
{
    protected $fillable = [
        'organization_id', 'location_id', 'code', 'name', 'kind',
        'manufacturer', 'model', 'serial_number', 'ip_address', 'mac_address',
        'firmware', 'regulatory_region', 'status', 'api_token_hash',
        'last_seen_at', 'settings',
    ];

    protected $hidden = ['api_token_hash'];

    protected function casts(): array
    {
        return [
            'kind' => DeviceKind::class,
            'last_seen_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function antennas(): HasMany
    {
        return $this->hasMany(DeviceAntenna::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'activo';
    }
}
