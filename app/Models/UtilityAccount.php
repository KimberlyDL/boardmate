<?php

namespace App\Models;

use App\Enums\BilledBy;
use App\Enums\UtilityMethod;
use App\Enums\UtilityType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One bill source attached to a property (D2). */
class UtilityAccount extends Model
{
    use SoftDeletes;

    protected $fillable = ['type', 'name', 'method', 'billed_by', 'notes'];

    protected function casts(): array
    {
        return [
            'type' => UtilityType::class,
            'method' => UtilityMethod::class,
            'billed_by' => BilledBy::class,
        ];
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
