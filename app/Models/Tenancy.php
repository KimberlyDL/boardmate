<?php

namespace App\Models;

use App\Enums\TenancyPaymentKind;
use App\Enums\TenancyStatus;
use App\Services\BillingCalendar\BillingCalendar;
use Carbon\CarbonImmutable;
use Database\Factories\TenancyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stay from move-in (System Design C1). In a bedspace room each bedspacer
 * has one; in a room rented whole the leader has it and the others are
 * occupants. Rent is due on the anchor day each month, paid in advance.
 */
class Tenancy extends Model
{
    /** @use HasFactory<TenancyFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return [
            'status' => TenancyStatus::class,
            'moved_in_on' => 'immutable_date',
            'anchor_day' => 'integer',
            'house_rules_accepted_at' => 'datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class)->withTrashed();
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(RentableUnit::class, 'unit_id')->withTrashed();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(BookingApplication::class, 'booking_application_id');
    }

    public function movedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moved_in_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(TenancyPayment::class)->orderBy('received_on')->orderBy('id');
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(TenancyDiscount::class)->orderByDesc('effective_from')->orderByDesc('id');
    }

    public function occupants(): HasMany
    {
        return $this->hasMany(RoomOccupant::class);
    }

    /** Tenancies still running (not ended or settled). */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['ended', 'settled']);
    }

    /** The next rent due date on or after $today: the end of the period that has not yet ended. */
    public function nextRentDueOn(CarbonImmutable $today): CarbonImmutable
    {
        $last = null;
        foreach (BillingCalendar::periods($this->moved_in_on, $this->anchor_day, $today) as $period) {
            $last = $period;
        }

        return $last->endsOn;
    }

    /** Total recorded for a kind of move-in payment, in centavos. */
    public function paidCentavos(TenancyPaymentKind $kind): int
    {
        return (int) $this->payments()->where('kind', $kind)->sum('amount_centavos');
    }
}
