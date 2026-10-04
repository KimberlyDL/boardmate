<?php

namespace App\Models;

use App\Enums\UnitKind;
use App\Enums\UnitStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** The whole property or one bedspace (D1). Rent is an effective-dated price rule. */
class RentableUnit extends Model
{
    use SoftDeletes;

    protected $fillable = ['kind', 'label', 'sort_order', 'capacity'];

    /** New units are free and ready (matches the column defaults). */
    protected $attributes = ['status' => 'available', 'not_ready' => false];

    protected function casts(): array
    {
        return [
            'kind' => UnitKind::class,
            'status' => UnitStatus::class,
            'not_ready' => 'boolean',
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

    /** Shown to the public: "Available now", "Leaving" etc. are computed in F1/F2. */
    public function isAvailable(): bool
    {
        return $this->status === UnitStatus::Available && ! $this->not_ready;
    }
}
