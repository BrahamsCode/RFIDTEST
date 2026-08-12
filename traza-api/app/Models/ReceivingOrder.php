<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ReceivingOrder extends Model
{
    protected $fillable = [
        'organization_id', 'supplier_id', 'location_id', 'code', 'external_ref',
        'status', 'expected_at', 'received_at', 'received_by', 'notes',
    ];

    protected function casts(): array
    {
        return ['expected_at' => 'datetime', 'received_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReceivingOrderLine::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
