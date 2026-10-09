<?php

namespace App\Models;

use App\Enums\PropertyType;
use App\Enums\RentalMode;
use App\Enums\UnitStatus;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * A dorm, apartment, boarding house, house or studio (System Design B1).
 * Structure: Building (optional) → Property → Room → Bedspace (optional).
 */
class Property extends Model
{
    use SoftDeletes;

    public const MAX_PHOTOS = 15;

    /** New properties start as unpublished drafts. */
    protected $attributes = ['is_published' => false];

    protected $fillable = [
        'building_id', 'name', 'type', 'description', 'who_can_apply',
        'street', 'barangay', 'city', 'province', 'latitude', 'longitude',
    ];

    protected function casts(): array
    {
        return [
            'type' => PropertyType::class,
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class)->ordered();
    }

    public function units(): HasMany
    {
        return $this->hasMany(RentableUnit::class)->orderBy('sort_order')->orderBy('id');
    }

    public function tenancies(): HasMany
    {
        return $this->hasMany(Tenancy::class);
    }

    public function utilityAccounts(): HasMany
    {
        return $this->hasMany(UtilityAccount::class)->orderBy('id');
    }

    public function settings(): HasOne
    {
        return $this->hasOne(PropertySettings::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(PropertyPhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    public function caretakerAssignments(): HasMany
    {
        return $this->hasMany(PropertyCaretaker::class);
    }

    /**
     * Properties a user may see: their own (owner), or those they are assigned
     * to as a caretaker. Nothing else, ever (owner data separation).
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(function (Builder $q) use ($user) {
            if ($user->hasAccountRole(UserRole::Owner)) {
                $q->orWhere('owner_id', $user->id);
            }
            if ($user->hasAccountRole(UserRole::Caretaker)) {
                $q->orWhereHas('caretakerAssignments', fn ($a) => $a->where('caretaker_id', $user->id));
            }
            $q->orWhereRaw('1 = 0');
        });
    }

    public function applications(): HasMany
    {
        return $this->hasMany(BookingApplication::class);
    }

    /** Units a boarder could book right now: Available and not marked Not ready. */
    public function bookableUnits(): HasMany
    {
        return $this->units()->where('status', UnitStatus::Available->value)->where('not_ready', false);
    }

    /**
     * Who decides on bookings: the owner and this property's Manager caretakers
     * (still working for the owner).
     *
     * @return Collection<int, User>
     */
    public function bookingApprovers(): Collection
    {
        $managers = User::query()
            ->whereIn('id', $this->caretakerAssignments()->where('access_level', 'manager')->select('caretaker_id'))
            ->whereHas('employerLinks', fn ($q) => $q->where('owner_id', $this->owner_id)->whereNull('removed_at'))
            ->get();

        return $managers->prepend($this->owner);
    }

    /**
     * Shown on Dorm Finder (F1): published, by a verified owner whose account
     * is active, with at least one bookable unit.
     *
     * @param  Builder<self>  $query
     */
    public function scopeListable(Builder $query): void
    {
        $query->where('is_published', true)
            ->whereHas('owner', fn (Builder $o) => $o->whereNull('suspended_at')
                ->whereHas('ownerProfile', fn (Builder $p) => $p->where('verification_status', 'verified')))
            ->whereHas('units', fn (Builder $u) => $u->where('status', UnitStatus::Available->value)->where('not_ready', false));
    }

    /**
     * Units that block deleting the property, switching a room's rental mode,
     * or removing them: anyone booked into or living there. Uses the same rule
     * as RentableUnit::isInUse().
     *
     * @return Collection<int, RentableUnit>
     */
    public function occupancyBlocks(): Collection
    {
        return $this->units()->inUse()->orderBy('label')->get();
    }

    /**
     * Lock this property's row for the rest of the transaction. Booking
     * approval takes the same lock, so an in-use check made after this cannot
     * be overtaken by an approval committing in between.
     */
    public function lockForOccupancyChange(): void
    {
        self::withTrashed()->whereKey($this->id)->lockForUpdate()->first();
    }

    /**
     * How the property is rented overall: 'whole' (every room whole),
     * 'bedspaces' (every room by bedspace) or 'mixed'.
     */
    public function rentalModeSummary(): string
    {
        $rooms = $this->relationLoaded('rooms') ? $this->rooms : $this->rooms()->get();
        $modes = $rooms->map(fn (Room $r) => $r->rental_mode->value)->unique()->values();

        return $modes->count() > 1 ? 'mixed' : ($modes->first() ?? RentalMode::Bedspaces->value);
    }

    public function rentalModeSummaryLabel(): string
    {
        $summary = $this->rentalModeSummary();

        return $summary === 'mixed' ? 'Mixed' : RentalMode::from($summary)->label();
    }

    public function hasUnitsInUse(): bool
    {
        return $this->units()->inUse()->exists();
    }

    public function fullAddress(): string
    {
        return collect([$this->street, $this->barangay, $this->city, $this->province])->filter()->implode(', ');
    }
}
