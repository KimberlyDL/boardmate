<?php

namespace App\Models;

use Database\Factories\RoomLeaderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The person responsible for a room (C2): billed for shared utilities, or for
 * everything when the room is rented whole, and admin of its shared
 * contributions group. One current leader per room; past leaders are kept.
 */
class RoomLeader extends Model
{
    /** @use HasFactory<RoomLeaderFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'consent_recorded_at' => 'datetime',
            'started_on' => 'immutable_date',
            'ended_on' => 'immutable_date',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function appointer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'appointed_by');
    }

    public function consentApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consent_approved_by');
    }

    /** Leaders who have not stepped down or been replaced. */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('ended_on');
    }
}
