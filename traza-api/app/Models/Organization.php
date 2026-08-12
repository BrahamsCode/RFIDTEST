<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Organization extends Model
{
    protected $fillable = [
        'name', 'tax_id', 'gs1_company_prefix', 'default_epc_scheme',
        'epc_filter_mask', 'timezone', 'settings',
    ];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
}
