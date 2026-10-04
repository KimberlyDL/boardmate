<?php

namespace App\Models;

use App\Enums\CaretakerAccessLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A caretaker working for an owner (removed_at set when the owner removes them). */
class OwnerCaretaker extends Model
{
    protected $fillable = ['owner_id', 'caretaker_id', 'access_level', 'removed_at'];

    protected function casts(): array
    {
        return [
            'access_level' => CaretakerAccessLevel::class,
            'removed_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function caretaker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caretaker_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('removed_at');
    }
}
