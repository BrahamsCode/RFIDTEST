<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Product extends Model
{
    protected $fillable = [
        'organization_id', 'category_id', 'season_id', 'supplier_id', 'code',
        'name', 'description', 'brand', 'composition', 'rfid_difficulty', 'is_active',
    ];

    protected function casts(): array
    {
        return ['rfid_difficulty' => 'integer', 'is_active' => 'boolean'];
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }
}
