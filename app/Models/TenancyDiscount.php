<?php

namespace App\Models;

use App\Enums\DiscountKind;
use Database\Factories\TenancyDiscountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An optional, manual "Agreed rate" discount on a tenancy's rent (C3). Rows
 * are effective-dated and never overwritten.
 */
class TenancyDiscount extends Model
{
    /** @use HasFactory<TenancyDiscountFactory> */
    use HasFactory;

    public const LABEL = 'Agreed rate';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => DiscountKind::class,
            'value' => 'integer',
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
        ];
    }

    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    /** Discounts in force on a day (calendar date in Manila). */
    public function scopeInForceOn(Builder $query, Carbon|string $day): Builder
    {
        $day = $day instanceof Carbon ? $day->toDateString() : $day;

        return $query->where('effective_from', '<=', $day)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $day));
    }

    /** The amount taken off a rent, in centavos (never more than the rent). */
    public function amountOff(int $rentCentavos): int
    {
        $off = match ($this->kind) {
            DiscountKind::Fixed => $this->value,
            DiscountKind::Percent => intdiv($rentCentavos * $this->value, 10000),
        };

        return min($off, $rentCentavos);
    }
}
