<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\TenancyPaymentKind;
use Database\Factories\TenancyPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The deposit or the advance rent received at move-in; never part of a bill. */
class TenancyPayment extends Model
{
    /** @use HasFactory<TenancyPaymentFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => TenancyPaymentKind::class,
            'method' => PaymentMethod::class,
            'amount_centavos' => 'integer',
            'received_on' => 'immutable_date',
        ];
    }

    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
