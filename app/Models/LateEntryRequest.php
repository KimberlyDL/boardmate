<?php

namespace App\Models;

use App\Enums\LateEntryStatus;
use Database\Factories\LateEntryRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A tenant's request to come back after curfew on a date (D2). */
class LateEntryRequest extends Model
{
    /** @use HasFactory<LateEntryRequestFactory> */
    use HasFactory;

    /** How far ahead a tenant can ask. */
    public const MAX_DAYS_AHEAD = 7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => LateEntryStatus::class,
            'on_date' => 'immutable_date',
            'decided_at' => 'datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class)->withTrashed();
    }

    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
