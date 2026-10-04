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

/** The whole property or one bedspace (D1). Rent is an effective-dated price rule. */
class RentableUnit extends Model
{
    use SoftDeletes;

    protected $fillable = ['kind', 'label', 'sort_order', 'capacity'];

    /** New units are free and ready (matches the column defaults). */
    protected $attributes = ['status' => 'available', 'not_ready' => false];

    protected function casts(): array
    {
        return [
            'kind' => UnitKind::class,
            'status' => UnitStatus::class,
            'not_ready' => 'boolean',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
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

    public function isAvailable(): bool
    {
        return $this->status === UnitStatus::Available && ! $this->not_ready;
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
