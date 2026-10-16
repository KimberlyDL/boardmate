<?php

namespace App\Models;

use App\Enums\BillKind;
use Database\Factories\BillFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A numbered bill to one payer (C4). Never edited or deleted once issued:
 * corrections are adjustment lines added to it.
 */
class Bill extends Model
{
    /** @use HasFactory<BillFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => BillKind::class,
            'period_start_on' => 'immutable_date',
            'period_end_on' => 'immutable_date',
            'issued_on' => 'immutable_date',
            'due_on' => 'immutable_date',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class)->withTrashed();
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_id');
    }

    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class)->withTrashed();
    }

    public function utilityBill(): BelongsTo
    {
        return $this->belongsTo(UtilityBill::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BillLine::class)->orderBy('sort_order')->orderBy('id');
    }

    /** What the bill comes to, adjustments included. Uses the loaded lines when present. */
    public function totalCentavos(): int
    {
        return (int) ($this->relationLoaded('lines') ? $this->lines->sum('amount_centavos') : $this->lines()->sum('amount_centavos'));
    }

    /**
     * Adds `balance_centavos` (what is still owed, as balanceCentavos()) to
     * each row, so lists can filter and show it without a query per bill.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithBalance(Builder $query): void
    {
        $query->select('bills.*')->selectRaw(self::BALANCE_SQL.' as balance_centavos');
    }

    /** @param  Builder<self>  $query */
    public function scopeUnpaid(Builder $query): void
    {
        $query->whereRaw(self::BALANCE_SQL.' > 0');
    }

    /** @param  Builder<self>  $query */
    public function scopePaid(Builder $query): void
    {
        $query->whereRaw(self::BALANCE_SQL.' <= 0');
    }

    private const BALANCE_SQL = '((select coalesce(sum(bl.amount_centavos), 0) from bill_lines bl where bl.bill_id = bills.id and bl.amount_centavos > 0)'
        .' - (select coalesce(sum(pa.amount_centavos), 0) from payment_allocations pa join bill_lines bl on bl.id = pa.bill_line_id where bl.bill_id = bills.id and bl.amount_centavos > 0))';

    /** What is still owed on its charges after payments and credits (PaymentLedger). */
    public function balanceCentavos(): int
    {
        if (array_key_exists('balance_centavos', $this->attributes)) {
            return (int) $this->attributes['balance_centavos'];
        }

        $applied = PaymentAllocation::whereIn('bill_line_id', $this->lines()->where('amount_centavos', '>', 0)->select('id'))->sum('amount_centavos');

        return (int) $this->lines()->where('amount_centavos', '>', 0)->sum('amount_centavos') - (int) $applied;
    }
}
