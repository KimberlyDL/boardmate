<?php

namespace App\Models;

use App\Enums\RentalMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A room of a property (System Design B1). It is rented whole or by bedspace
 * (its rental mode) and has an optional floor and an automatic code such as
 * B1-F4-03. A house or studio rented as a whole is a property with one room.
 */
class Room extends Model
{
    use SoftDeletes;

    protected $fillable = ['floor', 'number', 'rental_mode', 'sort_order'];

    protected function casts(): array
    {
        return [
            'rental_mode' => RentalMode::class,
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
     * Building 1, floor 4, room 3 is "B1-F4-03". The building part is left out
     * when the property has no building, and the floor part when the room has
     * no floor. Uses the loaded property and building when it can.
     */
    public function code(): string
    {
        $building = $this->property?->building;

        return collect([
            $building ? 'B'.$building->number : null,
            $this->floor !== null ? 'F'.$this->floor : null,
            str_pad((string) $this->number, 2, '0', STR_PAD_LEFT),
        ])->filter()->implode('-');
    }

    public function hasUnitsInUse(): bool
    {
        return $this->units()->inUse()->exists();
    }
}
