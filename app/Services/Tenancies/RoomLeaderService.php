<?php

namespace App\Services\Tenancies;

use App\Enums\AuditEvent;
use App\Enums\NotificationEvent;
use App\Enums\RentalMode;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomLeader;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\Audit\Contracts\AuditService;
use App\Services\Notifications\Contracts\NotificationService;
use App\Support\ManilaDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Room leaders (System Design C2): one per room, appointed by the owner or a
 * Manager, with the person's consent recorded.
 *
 * - The leader must live in the room (have a running tenancy there).
 * - In a room rented whole the leader is the person who rents it, so it is
 *   set at move-in and not replaced here (handing over the lease comes with
 *   move-out). A bedspace room's leader can be replaced.
 */
class RoomLeaderService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Make $user the room's leader, ending the previous leader's term, and
     * tell them (and the leader they replace). Move-in passes $notify = false:
     * the welcome message already says so, and it must not be sent before the
     * move-in is saved.
     *
     * @throws ValidationException when the appointment is not allowed
     */
    public function appoint(Room $room, User $user, User $actor, bool $consentRecorded, ?string $reason = null, bool $notify = true): RoomLeader
    {
        if (! $consentRecorded) {
            throw ValidationException::withMessages(['consent' => 'Confirm that this person agreed to be the leader.']);
        }

        [$leader, $previous] = DB::transaction(function () use ($room, $user, $actor, $reason) {
            // Same lock the booking and move-in flows take, so the room's people cannot change underneath us.
            Property::withTrashed()->whereKey($room->property_id)->lockForUpdate()->first();
            $room = Room::whereKey($room->id)->lockForUpdate()->firstOrFail();

            if ($user->isSuspended()) {
                throw ValidationException::withMessages(['user_id' => 'This account is suspended.']);
            }
            if (! Tenancy::current()->where('room_id', $room->id)->where('tenant_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['user_id' => 'Only someone who lives in this room can be its leader.']);
            }

            $current = RoomLeader::current()->where('room_id', $room->id)->first();
            if ($current?->user_id === $user->id) {
                throw ValidationException::withMessages(['user_id' => 'This person is already the leader.']);
            }
            if ($current && $room->rental_mode === RentalMode::Whole) {
                throw ValidationException::withMessages([
                    'user_id' => 'The leader of a room rented whole is the person who rents it. It changes when the lease is handed over.',
                ]);
            }

            $current?->update([
                'ended_on' => ManilaDate::today()->toDateString(),
                'ended_reason' => $reason ?: 'Replaced by the owner.',
            ]);

            $leader = RoomLeader::create([
                'room_id' => $room->id,
                'user_id' => $user->id,
                'appointed_by' => $actor->id,
                'consent_recorded_at' => now(),
                'consent_approved_by' => $actor->id,
                'started_on' => ManilaDate::today()->toDateString(),
            ]);

            return [$leader, $current];
        });

        $room->loadMissing('property.owner');
        $this->audit->record(
            $previous ? AuditEvent::LeaderReplaced : AuditEvent::LeaderAppointed,
            $room,
            owner: $room->property->owner,
            actor: $actor,
            reason: $reason,
            note: "{$room->property->name} · {$room->code()} · {$user->name}".($previous ? " (was {$previous->user->name})" : ''),
        );

        if ($notify) {
            $this->notifications->send($user, NotificationEvent::LeaderAppointed, [
                'room_code' => $room->code(),
                'property_name' => $room->property->name,
                'appointed_by' => $actor->name,
            ]);
            if ($previous) {
                $this->notifications->send($previous->user, NotificationEvent::LeaderEnded, [
                    'room_code' => $room->code(),
                    'property_name' => $room->property->name,
                    'new_leader' => $user->name,
                ]);
            }
        }

        return $leader->load('user');
    }
}
