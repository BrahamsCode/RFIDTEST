<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlertKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Alert extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'organization_id', 'location_id', 'kind', 'severity', 'status',
        'tag_id', 'device_id', 'title', 'detail', 'triggered_at',
        'acknowledged_by', 'acknowledged_at', 'resolved_at', 'resolution_note',
    ];

    protected function casts(): array
    {
        return [
            'kind' => AlertKind::class,
            'severity' => 'integer',
            'detail' => 'array',
            'triggered_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
