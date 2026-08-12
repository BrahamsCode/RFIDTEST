<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CycleScope;
use App\Enums\CycleStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class InventoryCycle extends Model
{
    protected $fillable = [
        'organization_id', 'location_id', 'code', 'scope', 'scope_filter',
        'status', 'started_by', 'started_at', 'closed_by', 'closed_at',
        'expected_count', 'counted_count', 'found_count', 'missing_count',
        'unexpected_count', 'accuracy_pct', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'scope' => CycleScope::class,
            'status' => CycleStatus::class,
            'scope_filter' => 'array',
            'started_at' => 'datetime',
            'closed_at' => 'datetime',
            'expected_count' => 'integer',
            'counted_count' => 'integer',
            'found_count' => 'integer',
            'missing_count' => 'integer',
            'unexpected_count' => 'integer',
            'accuracy_pct' => 'float',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
