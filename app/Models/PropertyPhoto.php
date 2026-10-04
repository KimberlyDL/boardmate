<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyPhoto extends Model
{
    protected $fillable = ['path', 'thumb_path', 'sort_order', 'is_cover'];

    protected function casts(): array
    {
        return ['is_cover' => 'boolean'];
    }

    /** Small version for cards; falls back to the large one for photos not rebuilt yet. */
    public function thumbOrLargePath(): string
    {
        return $this->thumb_path ?? $this->path;
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
