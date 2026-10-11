<?php

namespace App\Models;

use App\Enums\BillKind;
use Database\Factories\BillFactory;
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
}
