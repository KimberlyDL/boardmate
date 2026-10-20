<?php

namespace App\Http\Controllers\Api\V1\Groups;

use App\Authorization\GroupAccess;
use App\Enums\GroupRole;
use App\Enums\PropertyAbility as A;
use App\Enums\SplitMethod;
use App\Http\Controllers\Api\V1\Properties\Concerns\AuthorizesProperty;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ContributionGroup;
use App\Models\GroupMember;
use App\Models\Room;
use App\Models\RoomOccupant;
use App\Models\Tenancy;
use App\Services\Groups\GroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Shared-contributions groups (D5): one per room.
 *
 * @group Room groups
 */
class GroupController extends Controller
{
    use AuthorizesProperty;

    public function __construct(private readonly GroupService $groups) {}

    /**
     * My room groups
     *
     * Every room group I am or was in, the current one first.
     */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        $roomIds = Tenancy::where('tenant_id', $user->id)->pluck('room_id')
            ->merge(RoomOccupant::where('user_id', $user->id)->pluck('room_id'))->unique();

        $groups = Room::withTrashed()->whereIn('id', $roomIds)->with('property')->get()
            ->map(fn (Room $room) => $this->groups->forRoom($room))
            ->map(fn (ContributionGroup $g) => ['group' => $g, 'me' => GroupAccess::member($user, $g)])
            ->filter(fn (array $row) => $row['me'] !== null)
            ->sortBy(fn (array $row) => $row['me']->isCurrent() ? 0 : 1)
            ->values();

        return ApiResponse::ok($groups->map(fn (array $row) => [
            'id' => $row['group']->id,
            'room_label' => $row['group']->room->label(),
            'property_name' => $row['group']->room->property->name,
            'my_role' => $row['me']->role->value,
            'my_role_label' => $row['me']->role->label(),
            'is_current' => $row['me']->isCurrent(),
        ]));
    }

    /**
     * A room group
     *
     * Members see the whole group. The owner and caretakers see the members
     * only (and, later, the owner's own bills posted to the group).
     */
    public function show(Request $request, ContributionGroup $group): JsonResponse
    {
        $user = $request->user();
        $me = GroupAccess::member($user, $group);
        abort_unless($me || GroupAccess::isStaff($user, $group), 404);
        $this->groups->sync($group);

        return ApiResponse::ok($this->present($group->fresh(['room.property', 'members']), $me, $user));
    }

    /**
     * A room's group (staff)
     *
     * For the owner and caretakers: creates the group if needed.
     */
    public function forRoom(Request $request, Room $room): JsonResponse
    {
        $this->authorizeProperty($request, $room->property, A::View);
        $group = $this->groups->forRoom($room);

        return ApiResponse::ok($this->present($group->fresh(['room.property', 'members']), GroupAccess::member($request->user(), $group), $request->user()));
    }

    /**
     * Make someone a moderator (or a member again)
     *
     * By the leader, or the owner or a Manager. The leader is always the admin.
     */
    public function setRole(Request $request, ContributionGroup $group, GroupMember $member): JsonResponse
    {
        abort_unless(GroupAccess::canAppointModerators($request->user(), $group), 403, 'Only the room leader, the owner or a Manager can appoint moderators.');
        $data = $request->validate(['role' => ['required', Rule::in([GroupRole::Moderator->value, GroupRole::Member->value])]]);

        $member = $this->groups->setRole($group, $member, GroupRole::from($data['role']));

        return ApiResponse::ok(['id' => $member->id, 'role' => $member->role->value, 'role_label' => $member->role->label()],
            $member->role === GroupRole::Moderator ? "{$member->name} is now a moderator." : "{$member->name} is a member again.");
    }

    /**
     * Set the group's default split
     *
     * "equal" or "occupant_days" (by days stayed). By the leader or a moderator.
     */
    public function setDefaultSplit(Request $request, ContributionGroup $group): JsonResponse
    {
        abort_unless(GroupAccess::canManage($request->user(), $group), 403, 'Only the room leader or a moderator can change this.');
        $data = $request->validate(['method' => ['required', Rule::enum(SplitMethod::class)]]);

        $group = $this->groups->setDefaultSplit($group, SplitMethod::from($data['method']));

        return ApiResponse::ok(['default_split_method' => $group->default_split_method->value], 'Default split saved.');
    }

    /** @return array<string, mixed> */
    private function present(ContributionGroup $group, ?GroupMember $me, $user): array
    {
        $isMember = $me !== null;

        return [
            'id' => $group->id,
            'room' => ['id' => $group->room_id, 'label' => $group->room->label()],
            'property' => ['id' => $group->room->property_id, 'name' => $group->room->property->name],
            'default_split_method' => $group->default_split_method->value,
            'default_split_label' => $group->default_split_method->label(),
            'view' => $isMember ? 'member' : 'staff',
            'me' => $me ? ['id' => $me->id, 'role' => $me->role->value, 'is_current' => $me->isCurrent()] : null,
            'can_manage' => GroupAccess::canManage($user, $group),
            'can_appoint_moderators' => GroupAccess::canAppointModerators($user, $group),
            'members' => $group->members->map(fn (GroupMember $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'role' => $m->role->value,
                'role_label' => $m->role->label(),
                'has_account' => $m->user_id !== null,
                'joined_on' => $m->joined_on->toDateString(),
                'left_on' => $m->left_on?->toDateString(),
                'is_me' => $me?->id === $m->id,
            ])->values(),
        ];
    }
}
