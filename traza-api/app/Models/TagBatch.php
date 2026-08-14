<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lote de etiquetas emitido para una variante.
 *
 * `printed_ok` y `printed_void` son la contabilidad de inlays defectuosos:
 * un rollo tiene entre 0.1 % y 1 % de fallo, y por encima del 1 % hay que
 * reclamar al proveedor. Sin registrar los VOID no hay con qué reclamar.
 */
final class TagBatch extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id', 'product_variant_id', 'device_id', 'created_by',
        'quantity', 'serial_from', 'serial_to', 'printed_ok', 'printed_void',
        'notes', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'serial_from' => 'integer',
            'serial_to' => 'integer',
            'printed_ok' => 'integer',
            'printed_void' => 'integer',
            'created_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class, 'tag_batch_id');
    }

    /** Código legible por teléfono. No es la clave: la clave es el id. */
    public function code(): string
    {
        return sprintf('LOTE-%s-%04d', $this->created_at?->format('Ymd') ?? '00000000', $this->id);
    }

    /**
     * Tasa de inlays fallidos. Por encima del 1 % se reclama al proveedor
     * (`docs/04` §5).
     */
    public function voidRate(): float
    {
        $total = $this->printed_ok + $this->printed_void;

        return $total === 0 ? 0.0 : round($this->printed_void / $total * 100, 2);
    }
}
