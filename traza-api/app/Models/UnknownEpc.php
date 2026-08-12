<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** EPC visto que no pertenece al sistema. Solo para investigación. */
final class UnknownEpc extends Model
{
    public $timestamps = false;

    protected $table = 'unknown_epcs';

    protected $fillable = [
        'epc', 'location_id', 'device_id', 'first_seen_at', 'last_seen_at',
        'seen_count', 'resolved', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'seen_count' => 'integer',
            'resolved' => 'boolean',
        ];
    }
}
