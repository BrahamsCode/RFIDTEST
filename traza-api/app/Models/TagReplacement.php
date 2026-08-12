<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sustitución de tag por hangtag arrancado o ilegible. Ver P11. */
final class TagReplacement extends Model
{
    public $timestamps = false;

    protected $fillable = ['old_tag_id', 'new_tag_id', 'reason', 'performed_by', 'performed_at'];

    protected function casts(): array
    {
        return ['performed_at' => 'datetime'];
    }

    public function oldTag(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'old_tag_id');
    }

    public function newTag(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'new_tag_id');
    }
}
