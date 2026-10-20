<?php

use App\Enums\CaretakerAccessLevel;
use App\Enums\GroupRole;
use App\Enums\RentalMode;
use App\Models\ContributionGroup;
use App\Models\RoomLeader;
use App\Models\RoomOccupant;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\Properties\PropertySetup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Manila'));
    $this->owner = User::factory()->owner()->create();
    $this->property = makeProperty($this->owner, ['units' => ['count' => 3, 'rent_centavos' => 180000]]);
    $this->room = $this->property->rooms()->first();
    [$bed1, $bed2, $bed3] = $this->room->units()->get();
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $this->ben = User::factory()->boarder()->create(['name' => 'Ben']);
    $this->ana = User::factory()->boarder()->create(['name' => 'Ana']);
    Tenancy::factory()->forUnit($bed1)->movedInOn('2026-09-01')->create(['tenant_id' => $this->kim->id]);
    Tenancy::factory()->forUnit($bed2)->movedInOn('2026-09-25')->create(['tenant_id' => $this->ben->id]);
    Tenancy::factory()->forUnit($bed3)->movedInOn('2026-09-01')->create(['tenant_id' => $this->ana->id, 'status' => 'ended', 'end_on' => '2026-09-30', 'moved_out_on' => '2026-09-30']);
    RoomLeader::factory()->create(['room_id' => $this->room->id, 'user_id' => $this->kim->id]);
});

afterEach(fn () => Date::setTestNow());

it('creates a bedspace room\'s group on first use: its bedspacers, the leader as admin, and who left', function () {
    $this->property->settings->update(['split_method' => 'equal']);

    $this->actingAs($this->kim, 'sanctum')->getJson('/api/v1/me/groups')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.my_role', 'admin')
        ->assertJsonPath('data.0.my_role_label', 'Leader')
        ->assertJsonPath('data.0.is_current', true);

    $group = ContributionGroup::sole();
    $this->getJson("/api/v1/groups/{$group->id}")->assertOk()
        ->assertJsonPath('data.view', 'member')
        ->assertJsonPath('data.can_manage', true)
        ->assertJsonPath('data.default_split_method', 'equal') // from the property's setting
        ->assertJsonPath('data.members.0.name', 'Kim')
        ->assertJsonPath('data.members.0.is_me', true)
        ->assertJsonPath('data.members.1.name', 'Ana')
        ->assertJsonPath('data.members.1.left_on', '2026-09-30')
        ->assertJsonPath('data.members.2.name', 'Ben')
        ->assertJsonPath('data.members.2.role', 'member');

    // Ana left but still sees the group (read-only).
    $this->actingAs($this->ana, 'sanctum')->getJson('/api/v1/me/groups')->assertJsonPath('data.0.is_current', false);
    $this->getJson("/api/v1/groups/{$group->id}")->assertOk()->assertJsonPath('data.can_manage', false);
    $this->actingAs(User::factory()->boarder()->create(), 'sanctum')->getJson("/api/v1/groups/{$group->id}")->assertNotFound();
});

it('takes a whole room\'s members from its occupant list, with or without an account', function () {
    $whole = app(PropertySetup::class)->createRoom($this->property, RentalMode::Whole, ['capacity' => 3, 'rent_centavos' => 450000], $this->owner, ['door_no' => 'B']);
    $leo = User::factory()->boarder()->create(['name' => 'Leo']);
    $stay = Tenancy::factory()->forUnit($whole->units()->first())->movedInOn('2026-09-01')->create(['tenant_id' => $leo->id]);
    RoomOccupant::factory()->create(['room_id' => $whole->id, 'tenancy_id' => $stay->id, 'user_id' => $leo->id, 'name' => 'Leo', 'joined_on' => '2026-09-01']);
    RoomOccupant::factory()->create(['room_id' => $whole->id, 'tenancy_id' => $stay->id, 'name' => 'Ericka', 'joined_on' => '2026-09-10']);
    RoomLeader::factory()->create(['room_id' => $whole->id, 'user_id' => $leo->id]);

    $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/rooms/{$whole->id}/group")->assertOk()
        ->assertJsonPath('data.view', 'staff')
        ->assertJsonCount(2, 'data.members') // Leo once, not also as the tenant
        ->assertJsonPath('data.members.0.name', 'Leo')
        ->assertJsonPath('data.members.0.role', 'admin')
        ->assertJsonPath('data.members.1.name', 'Ericka')
        ->assertJsonPath('data.members.1.has_account', false);
});

it('moves the admin role with the leader, and keeps moderators', function () {
    $this->actingAs($this->kim, 'sanctum')->getJson('/api/v1/me/groups')->assertOk();
    $group = ContributionGroup::sole();
    $benMember = $group->members()->where('user_id', $this->ben->id)->first();
    $this->putJson("/api/v1/groups/{$group->id}/members/{$benMember->id}/role", ['role' => 'moderator'])->assertOk()
        ->assertJsonPath('message', 'Ben is now a moderator.');

    // Ben becomes the leader: he is the admin, Kim is a member.
    RoomLeader::query()->update(['ended_on' => '2026-10-20']);
    RoomLeader::factory()->create(['room_id' => $this->room->id, 'user_id' => $this->ben->id]);
    $this->getJson("/api/v1/groups/{$group->id}")->assertOk()
        ->assertJsonPath('data.members.0.role', 'member')
        ->assertJsonPath('data.members.2.role', 'admin')
        ->assertJsonPath('data.can_manage', false);
});

it('lets the leader, the owner or a Manager appoint moderators, never the members', function () {
    $collector = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Collector)->create();
    $manager = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Manager)->create();
    assignCaretaker($this->property, $collector, 'collector');
    assignCaretaker($this->property, $manager, 'manager');
    $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/rooms/{$this->room->id}/group")->assertOk();
    $group = ContributionGroup::sole();
    [$kim, $ana, $ben] = $group->members()->get()->all();
    $url = fn ($m) => "/api/v1/groups/{$group->id}/members/{$m->id}/role";

    $this->actingAs($this->ben, 'sanctum')->putJson($url($ben), ['role' => 'moderator'])->assertForbidden();
    $this->actingAs($collector, 'sanctum')->putJson($url($ben), ['role' => 'moderator'])->assertForbidden();
    $this->actingAs($manager, 'sanctum')->putJson($url($ben), ['role' => 'moderator'])->assertOk();
    $this->putJson($url($kim), ['role' => 'member'])->assertUnprocessable()->assertJsonValidationErrors(['role']); // the leader stays admin
    $this->putJson($url($ana), ['role' => 'moderator'])->assertUnprocessable(); // left the room
    $this->putJson($url($ben), ['role' => 'admin'])->assertUnprocessable()->assertJsonValidationErrors(['role']);
    expect($ben->fresh()->role)->toBe(GroupRole::Moderator);
});

it('lets the leader or a moderator set the default split to equal or by days stayed', function () {
    $this->actingAs($this->kim, 'sanctum')->getJson('/api/v1/me/groups')->assertOk();
    $group = ContributionGroup::sole();

    $this->putJson("/api/v1/groups/{$group->id}/default-split", ['method' => 'weights'])->assertUnprocessable()->assertJsonValidationErrors(['method']);
    $this->putJson("/api/v1/groups/{$group->id}/default-split", ['method' => 'equal'])->assertOk()->assertJsonPath('data.default_split_method', 'equal');
    $this->actingAs($this->ben, 'sanctum')->putJson("/api/v1/groups/{$group->id}/default-split", ['method' => 'occupant_days'])->assertForbidden();
    $this->actingAs($this->owner, 'sanctum')->putJson("/api/v1/groups/{$group->id}/default-split", ['method' => 'occupant_days'])->assertForbidden(); // the owner does not run the group
});
