<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An optional label grouping several properties for display; its number (B1, B2) is used in room codes (B1). */
class Building extends Model
{
    protected $fillable = ['name'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }
}
