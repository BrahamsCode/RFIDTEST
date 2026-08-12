<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ReceivingOrderLine extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'receiving_order_id', 'product_variant_id', 'expected_qty', 'received_qty', 'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'expected_qty' => 'integer',
            'received_qty' => 'integer',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
