<?php

namespace App\Models;

use App\Enums\RentalMode;
use App\Enums\WhoCanStay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A room of a property (System Design B1). It is rented whole or by bedspace
 * (its rental mode) and has an optional floor, an automatic code such as
 * F4-03, an optional door number and who it is for. A house or studio rented
 * as a whole is a property with one room.
 */
class Room extends Model
{
    use SoftDeletes;

    protected $fillable = ['floor', 'door_no', 'number', 'rental_mode', 'who_can_stay', 'sort_order'];

    /** New rooms are for anyone. */
    protected $attributes = ['who_can_stay' => 'any'];

    protected function casts(): array
    {
        return [
            'rental_mode' => RentalMode::class,
            'who_can_stay' => WhoCanStay::class,
            'floor' => 'integer',
            'number' => 'integer',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(RentableUnit::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Everyone who has led the room, newest first. */
    public function leaders(): HasMany
    {
        return $this->hasMany(RoomLeader::class)->orderByDesc('started_on')->orderByDesc('id');
    }

    /** The current leader (C2), if the room has one. */
    public function leader(): HasOne
    {
        return $this->hasOne(RoomLeader::class)->whereNull('ended_on');
    }

    /** People who stay in the room when it is rented whole. */
    public function occupants(): HasMany
    {
        return $this->hasMany(RoomOccupant::class)->orderBy('joined_on')->orderBy('id');
    }

    /** The room's shared-contributions group (D5), created automatically. */
    public function contributionGroup(): HasOne
    {
        return $this->hasOne(ContributionGroup::class);
    }

    public function tenancies(): HasMany
    {
        return $this->hasMany(Tenancy::class);
    }

    /** Rooms in display order: by floor (none first), then number. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByRaw('floor asc nulls first')->orderBy('number');
    }

    /**
     * The next free room number in the property, counting archived rooms so a
     * code is never reused.
     */
    public static function nextNumberFor(Property $property): int
    {
        return (int) self::withTrashed()->where('property_id', $property->id)->max('number') + 1;
    }

    /**
     * Floor 4, room 3 is "F4-03"; a room with no floor is just "03".
     */
    public function code(): string
    {
        return collect([
            $this->floor !== null ? 'F'.$this->floor : null,
            str_pad((string) $this->number, 2, '0', STR_PAD_LEFT),
        ])->filter()->implode('-');
    }

    /** What people call the room: its door number when set, else its code ("room 201", "room F2-03"). */
    public function label(): string
    {
        return $this->door_no ?? $this->code();
    }

    /**
     * Rented as one room right now: a whole room, or a bedspace room whose
     * whole-room option someone holds. Leader and occupant rules follow this.
     */
    public function isLetWhole(): bool
    {
        return $this->rental_mode === RentalMode::Whole
            || $this->units()->where('is_whole_option', true)->inUse()->exists();
    }

    /** The bedspace room's whole-room option, if the owner offers one. */
    public function wholeOption(): HasOne
    {
        return $this->hasOne(RentableUnit::class)->where('is_whole_option', true);
    }

    public function hasUnitsInUse(): bool
    {
        return $this->units()->inUse()->exists();
    }
}
