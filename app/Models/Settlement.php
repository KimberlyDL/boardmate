<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Database\Factories\SettlementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tenancy's deposit settlement after move-out (C8), once per tenancy.
 * deposit held = applied to bills + kept + (refund − credit returned);
 * anything not covered stays owed on the bills (still_owed at the time).
 * The refund also pays back any unused credit (credit_returned).
 */
class Settlement extends Model
{
    /** @use HasFactory<SettlementFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'deposit_held_centavos' => 'integer',
            'deductions_centavos' => 'integer',
            'deductions' => 'array',
            'credit_returned_centavos' => 'integer',
            'applied_centavos' => 'integer',
            'refund_centavos' => 'integer',
            'kept_centavos' => 'integer',
            'still_owed_centavos' => 'integer',
            'refund_method' => PaymentMethod::class,
            'refunded_on' => 'immutable_date',
        ];
    }

    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    /** The deposit applied to bills, as a payment with method "deposit". */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function settler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }
}
