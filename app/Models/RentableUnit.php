<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use App\Enums\UnitKind;
use App\Enums\UnitStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A whole room or one bedspace (B1). Rent is an effective-dated price rule.
 * A bedspace room may also have a whole unit, its "whole-room option"
 * (`is_whole_option`): it and the room's bedspaces block each other, so
 * `blocked` is set on one side while the other side is in use
 * (UnitStatusService::syncWholeOption).
 */
class RentableUnit extends Model
{
    use SoftDeletes;

    protected $fillable = ['room_id', 'kind', 'label', 'sort_order', 'capacity'];

    /** New units are free and ready (matches the column defaults). */
    protected $attributes = ['status' => 'available', 'not_ready' => false, 'blocked' => false, 'is_whole_option' => false];

    protected function casts(): array
    {
        return [
            'kind' => UnitKind::class,
            'status' => UnitStatus::class,
            'not_ready' => 'boolean',
            'blocked' => 'boolean',
            'is_whole_option' => 'boolean',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function priceRules(): MorphMany
    {
        return $this->morphMany(PriceRule::class, 'priceable')->orderByDesc('effective_from')->orderByDesc('id');
    }

    /** Shown to the public: "Available now", "Leaving" etc. are computed in F1/F2. */
    /** The approved application holding this unit (F2), if it is reserved. */
    public function activeReservation(): HasOne
    {
        return $this->hasOne(BookingApplication::class, 'unit_id')->where('status', ApplicationStatus::Approved);
    }

    /** The tenancy running in this unit, if someone has moved in. */
    public function currentTenancy(): HasOne
    {
        return $this->hasOne(Tenancy::class, 'unit_id')->whereNotIn('status', ['ended', 'settled']);
    }

    /** Bookable now: Available, ready, and not blocked by the other side of a whole-room option. */
    public function isAvailable(): bool
    {
        return $this->status === UnitStatus::Available && ! $this->not_ready && ! $this->blocked;
    }

    /** The query version of isAvailable(). */
    public function scopeFree(Builder $query): Builder
    {
        return $query->where('status', UnitStatus::Available->value)->where('not_ready', false)->where('blocked', false);
    }

    /** Real places (bedspaces or a whole room), leaving out a bedspace room's whole-room option. */
    public function scopePlaces(Builder $query): Builder
    {
        return $query->where('is_whole_option', false);
    }

    /**
     * Someone is booked into or living in this unit, so it cannot be removed
     * or switched. The one place this is decided (tenancies will extend it);
     * see Property::occupancyBlocks().
     */
    public function isInUse(): bool
    {
        return $this->status->isInUse();
    }

    public function scopeInUse(Builder $query): Builder
    {
        return $query->whereIn('status', UnitStatus::inUseValues());
    }
}
