<?php

namespace App\Services\Tenancies;

use App\Authorization\RoomAccess;
use App\Enums\AuditEvent;
use App\Enums\NotificationEvent;
use App\Enums\PropertyAbility;
use App\Enums\RentalMode;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomOccupant;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\Audit\AuditDiff;
use App\Services\Audit\Contracts\AuditService;
use App\Services\Notifications\Contracts\NotificationService;
use App\Support\ManilaDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Occupants of a room rented whole (System Design C2): who stays, with join
 * and leave dates for the occupant-days split. In a bedspace room the members
 * are the tenancies, so nothing is listed here.
 *
 * - The room must be rented whole and have somebody moved in.
 * - The people staying never exceed the unit's capacity; the owner raises the
 *   capacity first.
 * - Dates are calendar days: not before the move-in, never in the future.
 * - The holder (the leader whose tenancy the room is) is an occupant too, but
 *   cannot be removed or marked as left here.
 */
class RoomOccupantService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array{name: string, contact_phone?: string|null, email?: string|null, joined_on: string, emergency_contact_name: string, emergency_contact_relationship?: string|null, emergency_contact_phone: string}  $data
     */
    public function add(Room $room, array $data, User $actor): RoomOccupant
    {
        $occupant = DB::transaction(function () use ($room, $data, $actor) {
            $tenancy = $this->lockAndCheck($room);
            $this->assertRoomForOne($room, $tenancy);
            $this->assertJoinDate($data['joined_on'], $tenancy);

            $linked = $this->linkedAccount($data['email'] ?? null, $room);

            return RoomOccupant::create([
                'room_id' => $room->id,
                'tenancy_id' => null,
                'user_id' => $linked?->id,
                'name' => $data['name'],
                'contact_phone' => $data['contact_phone'] ?? null,
                'joined_on' => $data['joined_on'],
                'emergency_contact_name' => $data['emergency_contact_name'],
                'emergency_contact_relationship' => $data['emergency_contact_relationship'] ?? null,
                'emergency_contact_phone' => $data['emergency_contact_phone'],
                'added_by' => $actor->id,
            ]);
        });

        $this->record(AuditEvent::OccupantAdded, $occupant, [], $actor);
        $this->tellStaffIfLeader($room, $actor, "added {$occupant->name} as someone who stays");

        return $occupant;
    }

    /**
     * Change an occupant. $fields holds only what the caller may change (the
     * controller limits a leader to name, phone and leave date).
     *
     * @param  array<string, mixed>  $fields
     */
    public function update(RoomOccupant $occupant, array $fields, User $actor): RoomOccupant
    {
        $room = $occupant->room;
        $before = $occupant->only(array_keys($fields));

        DB::transaction(function () use ($occupant, $room, $fields) {
            $tenancy = $this->lockAndCheck($room);
            $occupant = RoomOccupant::whereKey($occupant->id)->lockForUpdate()->firstOrFail();

            $joined = $fields['joined_on'] ?? $occupant->joined_on->toDateString();
            $left = array_key_exists('left_on', $fields) ? $fields['left_on'] : $occupant->left_on?->toDateString();

            if (array_key_exists('joined_on', $fields)) {
                $this->assertJoinDate($joined, $tenancy);
            }
            if ($left !== null) {
                $this->assertLeaveDate($left, $joined);
            }
            if ($this->isHolder($occupant) && $left !== null) {
                throw ValidationException::withMessages(['left_on' => 'The person who rents the room cannot be marked as left here.']);
            }
            // Coming back (clearing the leave date) takes a place again.
            if ($occupant->left_on !== null && $left === null) {
                $this->assertRoomForOne($room, $tenancy);
            }

            $occupant->update($fields);
        });

        $occupant->refresh();
        $changes = AuditDiff::between($before, $occupant->only(array_keys($fields)));
        if ($changes !== []) {
            $this->record(AuditEvent::OccupantUpdated, $occupant, $changes, $actor);
        }
        if (array_key_exists('left_on', $fields) && $before['left_on'] === null && $occupant->left_on !== null) {
            $this->tellStaffIfLeader($room, $actor, "marked {$occupant->name} as left on ".$occupant->left_on->format('M j, Y'));
        }

        return $occupant;
    }

    public function remove(RoomOccupant $occupant, User $actor): void
    {
        DB::transaction(function () use ($occupant) {
            $this->lockAndCheck($occupant->room);
            $locked = RoomOccupant::whereKey($occupant->id)->lockForUpdate()->firstOrFail();
            if ($this->isHolder($locked)) {
                throw ValidationException::withMessages(['occupant' => 'The person who rents the room cannot be removed from it.']);
            }
            $locked->delete();
        });

        $this->record(AuditEvent::OccupantRemoved, $occupant, [], $actor);
    }

    /**
     * The owner and Managers hear about it when the room's leader changes who
     * stays; changes by staff themselves need no message.
     */
    private function tellStaffIfLeader(Room $room, User $actor, string $summary): void
    {
        if (RoomAccess::staffCan($actor, $room, PropertyAbility::ManageTenancies) || ! RoomAccess::isLeader($actor, $room)) {
            return;
        }

        $property = $room->property()->with('owner')->first();
        $this->notifications->send($property->bookingApprovers(), NotificationEvent::OccupantsChanged, [
            'property_id' => $property->id,
            'property_name' => $property->name,
            'room_code' => $room->code(),
            'leader_name' => $actor->name,
            'summary' => $summary,
        ]);
    }

    /** The person whose tenancy the room is (the leader). */
    public function isHolder(RoomOccupant $occupant): bool
    {
        return $occupant->tenancy_id !== null;
    }

    /**
     * Lock the property and room (the order booking and move-in use) and
     * check the room can have occupants at all. Returns the room's tenancy.
     */
    private function lockAndCheck(Room $room): Tenancy
    {
        Property::withTrashed()->whereKey($room->property_id)->lockForUpdate()->first();
        $room = Room::whereKey($room->id)->lockForUpdate()->firstOrFail();

        if ($room->rental_mode !== RentalMode::Whole) {
            throw ValidationException::withMessages([
                'room' => 'Occupants are listed only for rooms rented whole. In a bedspace room each person has their own tenancy.',
            ]);
        }

        $tenancy = Tenancy::current()->where('room_id', $room->id)->first();
        if (! $tenancy) {
            throw ValidationException::withMessages(['room' => 'Nobody has moved into this room yet.']);
        }

        return $tenancy;
    }

    private function assertRoomForOne(Room $room, Tenancy $tenancy): void
    {
        $capacity = (int) $tenancy->unit->capacity;
        $staying = RoomOccupant::where('room_id', $room->id)->whereNull('left_on')->count();

        if ($staying >= $capacity) {
            throw ValidationException::withMessages([
                'room' => "The room is full ({$capacity} ".($capacity === 1 ? 'person' : 'people').'). Raise its capacity first.',
            ]);
        }
    }

    private function assertJoinDate(string $joinedOn, Tenancy $tenancy): void
    {
        if ($joinedOn > ManilaDate::today()->toDateString()) {
            throw ValidationException::withMessages(['joined_on' => 'The join date cannot be in the future.']);
        }
        if ($joinedOn < $tenancy->moved_in_on->toDateString()) {
            throw ValidationException::withMessages(['joined_on' => 'The join date cannot be before the room was moved into ('.$tenancy->moved_in_on->format('M j, Y').').']);
        }
    }

    private function assertLeaveDate(string $leftOn, string $joinedOn): void
    {
        if ($leftOn > ManilaDate::today()->toDateString()) {
            throw ValidationException::withMessages(['left_on' => 'The leave date cannot be in the future.']);
        }
        if ($leftOn < $joinedOn) {
            throw ValidationException::withMessages(['left_on' => 'The leave date cannot be before the join date.']);
        }
    }

    /** The account behind an email, if one is given; it must exist and not be suspended or already staying here. */
    private function linkedAccount(?string $email, Room $room): ?User
    {
        if ($email === null || $email === '') {
            return null;
        }

        $user = User::where('email', mb_strtolower($email))->first();
        if (! $user) {
            throw ValidationException::withMessages(['email' => 'No BoardMate account uses this email. Leave it out to add the person without an account.']);
        }
        if ($user->isSuspended()) {
            throw ValidationException::withMessages(['email' => 'This account is suspended.']);
        }
        if (RoomOccupant::where('room_id', $room->id)->whereNull('left_on')->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'This person is already listed in the room.']);
        }

        return $user;
    }

    /** @param  array<string, array{0: mixed, 1: mixed}>  $changes */
    private function record(AuditEvent $event, RoomOccupant $occupant, array $changes, User $actor): void
    {
        $room = $occupant->room()->with('property.owner')->first();

        $this->audit->record($event, $room, $changes, owner: $room->property->owner, actor: $actor,
            note: "{$room->property->name} · {$room->code()} · {$occupant->name}");
    }
}
