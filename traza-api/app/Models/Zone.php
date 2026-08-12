<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Zone extends Model
{
    protected $fillable = [
        'location_id', 'code', 'name', 'kind', 'counts_as_sellable', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['counts_as_sellable' => 'boolean', 'sort_order' => 'integer'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
