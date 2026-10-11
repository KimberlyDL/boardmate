<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Part of a payment (or of a credit line) applied to one charge line. */
class PaymentAllocation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_centavos' => 'integer'];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(BillLine::class, 'bill_line_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function creditLine(): BelongsTo
    {
        return $this->belongsTo(BillLine::class, 'credit_line_id');
    }
}
