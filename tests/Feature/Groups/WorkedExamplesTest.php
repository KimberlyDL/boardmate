<?php

use App\Enums\RepaymentStatus;
use App\Models\GroupExpense;
use App\Models\GroupMember;
use App\Models\GroupRepayment;
use App\Models\RoomLeader;
use App\Models\RoomOccupant;
use App\Models\Tenancy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

/*
| The Billing guide's worked examples, run end to end through a room group:
| SC-06 (a newcomer mid-period), SC-12 (amounts that do not add up), SC-13
| (₱1,000 three ways) and SC-31 (the boarders pay the internet themselves).
| One room rented whole: Leo (leader, from Sep 1) and Ericka (from Sep 27).
*/

beforeEach(function () {
    Notification::fake();
    Date::setTestNow(CarbonImmutable::parse('2026-09-01 10:00', 'Asia/Manila'));

    $this->owner = User::factory()->owner()->create();
    $this->property = makeProperty($this->owner, ['rental_mode' => 'whole', 'units' => ['capacity' => 3, 'rent_centavos' => 450000]]);
    $this->room = $this->property->rooms()->first();
    $this->leo = User::factory()->boarder()->create(['name' => 'Leo']);
    $this->ericka = User::factory()->boarder()->create(['name' => 'Ericka']);
    $this->stay = Tenancy::factory()->forUnit($this->room->units()->first())->movedInOn('2026-09-01')->create(['tenant_id' => $this->leo->id]);
    RoomOccupant::factory()->create(['room_id' => $this->room->id, 'tenancy_id' => $this->stay->id, 'user_id' => $this->leo->id, 'name' => 'Leo', 'joined_on' => '2026-09-01']);
    RoomOccupant::factory()->create(['room_id' => $this->room->id, 'tenancy_id' => $this->stay->id, 'user_id' => $this->ericka->id, 'name' => 'Ericka', 'joined_on' => '2026-09-27']);
    RoomLeader::factory()->create(['room_id' => $this->room->id, 'user_id' => $this->leo->id]);
    $this->meralco = $this->property->utilityAccounts()->create(['type' => 'electricity', 'name' => 'Meralco', 'method' => 'actual_bill', 'billed_by' => 'owner']);

    Date::setTestNow(CarbonImmutable::parse('2026-10-12 10:00', 'Asia/Manila'));
    $this->group = $this->actingAs($this->leo, 'sanctum')->getJson('/api/v1/me/groups')->assertOk()->json('data.0.id');
    $this->url = "/api/v1/groups/{$this->group}";
});

afterEach(fn () => Date::setTestNow());

/** The member called $name in the group. */
function memberNamed(string $name): GroupMember
{
    return GroupMember::where('group_id', test()->group)->where('name', $name)->sole();
}

/** member name => centavos for a line */
function workedShares(int $lineId): array
{
    return GroupExpense::findOrFail($lineId)->shares()->with('member')->get()->mapWithKeys(fn ($s) => [$s->member->name => $s->amount_centavos])->all();
}

it('SC-06: a newcomer 13 of 30 days of a ₱1,080 bill pays ₱326.51 by days, ₱540 equally, or nothing until the next bill', function () {
    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/properties/{$this->property->id}/utility-bills", [
        'utility_account_id' => $this->meralco->id, 'coverage_from_on' => '2026-09-10', 'coverage_to_on' => '2026-10-09', 'total_centavos' => 108000,
    ])->assertCreated();

    $line = GroupExpense::where('group_id', $this->group)->sole();
    expect($line->amount_centavos)->toBe(108000)
        ->and(workedShares($line->id))->toBe(['Leo' => 75349, 'Ericka' => 32651]);

    $split = "{$this->url}/expenses/{$line->id}/split";
    $this->actingAs($this->leo, 'sanctum')->putJson($split, ['method' => 'equal'])->assertOk();
    expect(workedShares($line->id))->toBe(['Leo' => 54000, 'Ericka' => 54000]);

    // "Newcomer pays from the next bill": fixed amounts ₱1,080 and ₱0.
    $this->putJson($split, ['method' => 'custom', 'amounts' => [memberNamed('Leo')->id => 108000, memberNamed('Ericka')->id => 0]])->assertOk();
    expect(workedShares($line->id))->toBe(['Leo' => 108000]); // a ₱0 share is not stored
});

it('SC-12: fixed amounts that do not add up are refused with the gap, then saved once they do', function () {
    [$leo, $ericka] = [memberNamed('Leo')->id, memberNamed('Ericka')->id];
    $expense = ['description' => 'Cleaning things', 'amount_centavos' => 60000, 'spent_on' => '2026-10-10', 'method' => 'custom', 'participants' => [$leo, $ericka]];

    $this->actingAs($this->ericka, 'sanctum')->postJson("{$this->url}/expenses", $expense + ['amounts' => [$leo => 10000, $ericka => 40000]])
        ->assertUnprocessable()
        ->assertJsonPath('errors.amounts.0', 'The amounts add up to ₱500.00; the line is ₱600.00.');
    expect(GroupExpense::count())->toBe(0);

    $id = $this->postJson("{$this->url}/expenses", $expense + ['amounts' => [$leo => 10000, $ericka => 50000]])->assertCreated()->json('data.id');
    expect(workedShares($id))->toBe(['Leo' => 10000, 'Ericka' => 50000]);
});

it('SC-13: ₱1,000 three ways is ₱333.34, ₱333.33 and ₱333.33, exactly ₱1,000 in all', function () {
    RoomOccupant::factory()->create(['room_id' => $this->room->id, 'tenancy_id' => $this->stay->id, 'name' => 'Mia', 'joined_on' => '2026-09-01']);

    $id = $this->postJson("{$this->url}/expenses", ['description' => 'Water refills', 'amount_centavos' => 100000, 'spent_on' => '2026-10-10'])
        ->assertCreated()->json('data.id');

    $shares = workedShares($id);
    rsort($shares);
    expect($shares)->toBe([33334, 33333, 33333])
        ->and(array_sum($shares))->toBe(100000);
});

it('SC-31: one boarder pays the internet, the others pay her back, and the room ends even', function () {
    // Mia has no account: the leader records her cash for her.
    RoomOccupant::factory()->create(['room_id' => $this->room->id, 'tenancy_id' => $this->stay->id, 'name' => 'Mia', 'joined_on' => '2026-09-01']);
    $this->actingAs($this->ericka, 'sanctum')->postJson("{$this->url}/expenses", ['description' => 'Internet, October', 'amount_centavos' => 150000, 'spent_on' => '2026-10-05'])
        ->assertCreated()->assertJsonPath('data.my_share_centavos', 50000);
    $this->getJson("{$this->url}/balances")->assertOk()->assertJsonPath('data.me.owed_to_me_centavos', 100000);

    // Leo pays back online; Mia pays cash, recorded by Leo. Ericka confirms both.
    [$ericka, $mia] = [memberNamed('Ericka')->id, memberNamed('Mia')->id];
    $this->actingAs($this->leo, 'sanctum');
    $this->postJson("{$this->url}/repayments", ['to_member_id' => $ericka, 'amount_centavos' => 50000, 'method' => 'online', 'reference' => 'GC-881'])->assertCreated();
    $this->postJson("{$this->url}/repayments", ['to_member_id' => $ericka, 'from_member_id' => $mia, 'amount_centavos' => 50000, 'method' => 'cash'])->assertCreated();
    expect(GroupRepayment::where('status', RepaymentStatus::Pending)->count())->toBe(2);

    $this->actingAs($this->ericka, 'sanctum');
    foreach (GroupRepayment::pluck('id') as $id) {
        $this->postJson("{$this->url}/repayments/{$id}/confirm")->assertOk();
    }

    $this->getJson("{$this->url}/balances")->assertOk()
        ->assertJsonPath('data.me.owed_to_me_centavos', 0)
        ->assertJsonPath('data.me.owe_centavos', 0);
    $this->actingAs($this->leo, 'sanctum')->getJson("{$this->url}/balances")->assertJsonCount(0, 'data.pairs');
    // The owner's bills carry no internet line: it never reached the owner (billed by group).
    $this->actingAs($this->owner, 'sanctum')->getJson("{$this->url}/expenses")->assertOk()->assertJsonCount(0, 'data');
});
