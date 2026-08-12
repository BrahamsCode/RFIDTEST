<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Transfer extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id', 'code', 'from_location_id', 'to_location_id', 'status',
        'dispatched_at', 'received_at', 'dispatched_by', 'received_by', 'notes',
    ];

    protected function casts(): array
    {
        return ['dispatched_at' => 'datetime', 'received_at' => 'datetime'];
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'transfer_tags')
            ->withPivot(['dispatched', 'received']);
    }
}
