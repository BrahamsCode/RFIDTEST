<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tránsito detectado por un portal.
 *
 * Guarda toda la evidencia (secuencia de antenas y RSSI) aunque no dispare
 * alarma: la clasificación de dirección es heurística y falla en trazas
 * ambiguas, así que conservar la evidencia es lo único que permite revisar
 * una alarma discutida.
 */
final class PortalEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'device_id', 'location_id', 'tag_id', 'epc', 'direction',
        'confidence', 'was_sold', 'alarm_raised', 'occurred_at', 'evidence',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'was_sold' => 'boolean',
            'alarm_raised' => 'boolean',
            'occurred_at' => 'datetime',
            'evidence' => 'array',
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

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
