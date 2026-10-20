<?php

namespace App\Services\Groups;

use App\Enums\GroupRole;
use App\Enums\SplitMethod;
use App\Models\ContributionGroup;
use App\Models\GroupMember;
use App\Models\PropertySettings;
use App\Models\Room;
use App\Models\RoomLeader;
use App\Models\RoomOccupant;
use App\Models\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A room's shared-contributions group (D5): created the first time it is
 * needed and kept in step with the room every time it is read.
 *
 * - Members: every bedspacer of the room (tenancies), and every listed
 *   occupant of a whole room (an account is optional). Someone who left
 *   keeps their membership with a leave date, so old shares keep their names.
 * - The room's current leader is the admin. Moderators are appointed by the
 *   leader or by the owner or a Manager; everyone else is a member.
 * - The default split starts from the property's "How bills are split".
 */
class GroupService
{
    /** The only split methods a group may use as its default (they need no extra numbers). */
    public const DEFAULT_METHODS = [SplitMethod::Equal, SplitMethod::OccupantDays];

    public function forRoom(Room $room): ContributionGroup
    {
        $group = ContributionGroup::firstOrCreate(
            ['room_id' => $room->id],
            ['default_split_method' => $this->propertyDefault($room)],
        );
        $this->sync($group->setRelation('room', $room));

        return $group->load('members.user:id,name');
    }

    /** Bring members, leave dates and the admin in line with the room as it is now. */
    public function sync(ContributionGroup $group): void
    {
        $room = $group->room;

        DB::transaction(function () use ($group, $room) {
            // Bedspacers (and whole-room tenants without an occupant list): one member per tenancy.
            $listed = RoomOccupant::where('room_id', $room->id)->whereNotNull('tenancy_id')->pluck('tenancy_id')->flip();
            Tenancy::where('room_id', $room->id)->with('tenant:id,name')->get()
                ->reject(fn (Tenancy $t) => $listed->has($t->id))
                ->each(fn (Tenancy $t) => $this->upsert($group, ['tenancy_id' => $t->id], [
                    'user_id' => $t->tenant_id,
                    'name' => $t->tenant->name,
                    'joined_on' => $t->moved_in_on->toDateString(),
                    'left_on' => $t->moved_out_on?->toDateString(),
                ]));

            // A whole room's listed occupants (the tenant is one of them).
            RoomOccupant::where('room_id', $room->id)->get()
                ->each(fn (RoomOccupant $o) => $this->upsert($group, ['room_occupant_id' => $o->id], [
                    'user_id' => $o->user_id,
                    'name' => $o->name,
                    'joined_on' => $o->joined_on->toDateString(),
                    'left_on' => $o->left_on?->toDateString(),
                ]));

            // The leader is the admin; a former admin goes back to member (moderators stay).
            $leaderId = RoomLeader::current()->where('room_id', $room->id)->value('user_id');
            $group->members()->where('role', GroupRole::Admin->value)
                ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $leaderId ?? 0)->orWhereNotNull('left_on'))
                ->update(['role' => GroupRole::Member->value]);
            if ($leaderId) {
                $group->members()->where('user_id', $leaderId)->whereNull('left_on')
                    ->update(['role' => GroupRole::Admin->value]);
            }
        });

        $group->unsetRelation('members');
    }

    /** The leader, the owner or a Manager makes a current member a moderator, or a member again. */
    public function setRole(ContributionGroup $group, GroupMember $member, GroupRole $role): GroupMember
    {
        if ($member->group_id !== $group->id) {
            abort(404);
        }
        if ($role === GroupRole::Admin || $member->role === GroupRole::Admin) {
            throw ValidationException::withMessages(['role' => 'The room leader is always the admin. Change the leader instead.']);
        }
        if (! $member->isCurrent()) {
            throw ValidationException::withMessages(['role' => "{$member->name} has left the room."]);
        }
        $member->update(['role' => $role]);

        return $member;
    }

    public function setDefaultSplit(ContributionGroup $group, SplitMethod $method): ContributionGroup
    {
        if (! in_array($method, self::DEFAULT_METHODS, true)) {
            throw ValidationException::withMessages(['method' => 'The group default can be "Equal" or "By days stayed". Other methods are chosen line by line.']);
        }
        $group->update(['default_split_method' => $method]);

        return $group;
    }

    private function upsert(ContributionGroup $group, array $key, array $values): void
    {
        $member = $group->members()->where($key)->first();
        if ($member) {
            $member->update($values);
        } else {
            $group->members()->create($key + $values + ['role' => GroupRole::Member]);
        }
    }

    private function propertyDefault(Room $room): SplitMethod
    {
        $method = ($room->property->settings ?? new PropertySettings)->split_method;

        return in_array($method, self::DEFAULT_METHODS, true) ? $method : SplitMethod::OccupantDays;
    }
}
