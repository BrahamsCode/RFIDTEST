<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SaleTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id', 'location_id', 'code', 'external_ref', 'total_amount',
        'currency', 'sold_at', 'user_id', 'is_return', 'original_sale_id',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:4',
            'sold_at' => 'datetime',
            'is_return' => 'boolean',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaleLine::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
