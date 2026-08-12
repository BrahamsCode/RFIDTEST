<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MovementType;
use App\Enums\TagState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fuente de verdad del stock. Append-only: la base de datos rechaza UPDATE y
 * DELETE mediante el trigger `forbid_mutation()`.
 *
 * Solo `StockMovementService` escribe aquí. Ver `docs/06` §2.
 */
final class StockMovement extends Model
{
    /** La tabla no tiene `updated_at`: un movimiento nunca se modifica. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'tag_id',
        'product_variant_id',
        'movement_type',
        'quantity',
        'from_location_id',
        'from_zone_id',
        'to_location_id',
        'to_zone_id',
        'state_before',
        'state_after',
        'user_id',
        'device_id',
        'reference_type',
        'reference_id',
        'reason',
        'unit_cost',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'movement_type' => MovementType::class,
            'state_before' => TagState::class,
            'state_after' => TagState::class,
            'quantity' => 'integer',
            'unit_cost' => 'decimal:4',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
