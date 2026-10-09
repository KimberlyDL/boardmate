<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A boarder's application to a property; once approved, their reservation. */
class BookingApplication extends Model
{
    protected $fillable = ['planned_move_in_on', 'message', 'contact_phone'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'planned_move_in_on' => 'immutable_date',
            'reserved_until' => 'immutable_date',
            'decided_at' => 'datetime',
            'closed_at' => 'datetime',
            'moved_in_at' => 'datetime',
            'planned_occupants' => 'array',
            'leader_on_move_in' => 'boolean',
            'id_document_purged_at' => 'datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(RentableUnit::class, 'unit_id')->withTrashed();
    }

    public function boarder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'boarder_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', ApplicationStatus::openValues());
    }

    /**
     * Applications on properties the user may approve bookings for: their own,
     * or those they manage as a Manager caretaker (active owner link).
     *
     * @param  Builder<self>  $query
     */
    public function scopeReviewableBy(Builder $query, User $user): void
    {
        $query->whereHas('property', fn (Builder $p) => $p->where(function (Builder $q) use ($user) {
            $q->where('owner_id', $user->id)
                ->orWhereExists(fn ($e) => $e->from('property_caretakers')
                    ->whereColumn('property_caretakers.property_id', 'properties.id')
                    ->where('property_caretakers.caretaker_id', $user->id)
                    ->where('property_caretakers.access_level', 'manager')
                    ->whereExists(fn ($l) => $l->from('owner_caretakers')
                        ->whereColumn('owner_caretakers.owner_id', 'properties.owner_id')
                        ->where('owner_caretakers.caretaker_id', $user->id)
                        ->whereNull('owner_caretakers.removed_at')));
        }));
    }
}
