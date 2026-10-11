<?php

namespace App\Models;

use App\Enums\BilledBy;
use App\Enums\UtilityMethod;
use App\Enums\UtilityType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One bill source attached to a property (D2). */
class UtilityAccount extends Model
{
    use SoftDeletes;

    protected $fillable = ['room_id', 'type', 'name', 'method', 'billed_by', 'notes'];

    protected function casts(): array
    {
        return [
            'type' => UtilityType::class,
            'method' => UtilityMethod::class,
            'billed_by' => BilledBy::class,
        ];
    }

    /** The room whose own meter this is; null when it covers the whole property. */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class)->withTrashed();
    }

    /** The provider's bills entered for an actual-bill account. */
    public function utilityBills(): HasMany
    {
        return $this->hasMany(UtilityBill::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function priceRules(): MorphMany
    {
        return $this->morphMany(PriceRule::class, 'priceable')->orderByDesc('effective_from')->orderByDesc('id');
    }
}
