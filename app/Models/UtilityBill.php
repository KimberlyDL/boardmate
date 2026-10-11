<?php

namespace App\Models;

use Database\Factories\UtilityBillFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A provider's bill for an actual-bill utility account (C3, SC-03): its
 * coverage dates and total, entered when it arrives. Its shares are billed to
 * the rooms' leaders as utility bills. A correction replaces it (`replaces_id`).
 */
class UtilityBill extends Model
{
    /** @use HasFactory<UtilityBillFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'coverage_from_on' => 'immutable_date',
            'coverage_to_on' => 'immutable_date',
            'total_centavos' => 'integer',
            'shares' => 'array',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class)->withTrashed();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(UtilityAccount::class, 'utility_account_id')->withTrashed();
    }

    /** The bills that carry its shares, one per room. */
    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_id');
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
