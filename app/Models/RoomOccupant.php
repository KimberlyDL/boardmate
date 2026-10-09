<?php

namespace App\Models;

use Database\Factories\RoomOccupantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone who stays in a room rented whole (C2). The owner and the property's
 * caretakers see the emergency contact; the leader and other members do not.
 */
class RoomOccupant extends Model
{
    /** @use HasFactory<RoomOccupantFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'joined_on' => 'immutable_date',
            'left_on' => 'immutable_date',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class)->withTrashed();
    }

    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    /** Occupants who have not left. */
    public function scopeStaying(Builder $query): Builder
    {
        return $query->whereNull('left_on');
    }
}
