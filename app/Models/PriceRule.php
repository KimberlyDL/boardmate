<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** An effective-dated price (S4). Created and closed only by App\Services\Pricing\PriceBook. */
class PriceRule extends Model
{
    protected $fillable = ['property_id', 'amount_centavos', 'effective_from', 'effective_to', 'set_by'];

    protected function casts(): array
    {
        return [
            'amount_centavos' => 'integer',
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
        ];
    }

    public function priceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
