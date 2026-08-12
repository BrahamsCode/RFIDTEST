<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TagState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una prenda identificada por su EPC.
 *
 * El estado espacial (`state`, `current_location_id`, `current_zone_id`) es
 * una proyección de `stock_movements`, no una segunda fuente de verdad. Solo
 * `StockMovementService` puede escribirlo. Ver `docs/06` §2.
 */
final class Tag extends Model
{
    protected $fillable = [
        'organization_id',
        'epc',
        'epc_scheme',
        'tid',
        'product_variant_id',
        'tag_batch_id',
        'state',
        'current_location_id',
        'current_zone_id',
        'commissioned_at',
        'first_seen_at',
        'last_seen_at',
        'sold_at',
        'missed_cycles',
        'replaces_tag_id',
        'decoded_company_prefix',
        'decoded_item_reference',
        'decoded_serial',
    ];

    protected function casts(): array
    {
        return [
            'state' => TagState::class,
            'commissioned_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'sold_at' => 'datetime',
            'missed_cycles' => 'integer',
            'decoded_serial' => 'integer',
        ];
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function currentLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'current_location_id');
    }

    public function currentZone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'current_zone_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->orderByDesc('occurred_at');
    }

    /** Los EPC se guardan siempre en hexadecimal mayúscula, sin separadores. */
    public function setEpcAttribute(string $value): void
    {
        $this->attributes['epc'] = strtoupper($value);
    }

    public function setTidAttribute(?string $value): void
    {
        $this->attributes['tid'] = $value === null ? null : strtoupper($value);
    }
}
