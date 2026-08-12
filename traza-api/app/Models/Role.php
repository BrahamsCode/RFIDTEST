<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Role extends Model
{
    public $timestamps = false;

    protected $fillable = ['code', 'name', 'permissions'];

    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
