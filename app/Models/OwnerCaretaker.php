<?php

namespace App\Models;

use App\Enums\CaretakerAccessLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

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

    /**
     * Every property assignment of this caretaker, across all owners; filter
     * by owner with assignmentsHere(). Eager-loadable for lists.
     */
    public function caretakerAssignments(): HasMany
    {
        return $this->hasMany(PropertyCaretaker::class, 'caretaker_id', 'caretaker_id');
    }

    /** @return Collection<int, PropertyCaretaker> this owner's (non-deleted) properties only */
    public function assignmentsHere(): Collection
    {
        $this->loadMissing('caretakerAssignments.property:id,name,owner_id');

        return $this->caretakerAssignments
            ->filter(fn (PropertyCaretaker $a) => $a->property?->owner_id === $this->owner_id)
            ->sortBy('property_id')
            ->values();
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('removed_at');
    }
}
