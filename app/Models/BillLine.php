<?php

namespace App\Models;

use App\Enums\BillLineKind;
use Database\Factories\BillLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of a bill. A positive line is a charge that payments settle; a
 * negative line (an adjustment lowering a charge) is a credit that settles
 * other charges like a payment would.
 */
class BillLine extends Model
{
    /** @use HasFactory<BillLineFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => BillLineKind::class,
            'amount_centavos' => 'integer',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(RentableUnit::class, 'unit_id')->withTrashed();
    }

    public function priceRule(): BelongsTo
    {
        return $this->belongsTo(PriceRule::class);
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(TenancyDiscount::class, 'discount_id');
    }

    public function adjusts(): BelongsTo
    {
        return $this->belongsTo(self::class, 'adjusts_line_id');
    }

    /** Money applied to this charge (from payments or credits). */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /** Where this credit line's amount went, when it is a credit. */
    public function creditAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'credit_line_id');
    }

    public function isCharge(): bool
    {
        return $this->amount_centavos > 0;
    }

    public function isCredit(): bool
    {
        return $this->amount_centavos < 0;
    }
}
