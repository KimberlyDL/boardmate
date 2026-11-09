<?php

use App\Enums\GroupExpenseKind;
use App\Enums\RepaymentStatus;
use App\Models\ContributionGroup;
use App\Models\GroupExpense;
use App\Models\GroupRepayment;
use App\Models\RoomLeader;
use App\Models\Tenancy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

/**
 * "Collect first": Kim (leader), Ben and Ana. The ₱1,500 internet is due on
 * Nov 5. Kim adds it on Oct 20 so everyone pays ₱500 before it is due,
 * instead of Kim paying it all and waiting to be paid back.
 */
beforeEach(function () {
    Notification::fake();
    Date::setTestNow(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Manila'));
    $this->owner = User::factory()->owner()->create();
    $this->property = makeProperty($this->owner);
    $room = $this->property->rooms()->first();
    foreach (['kim', 'ben', 'ana'] as $i => $name) {
        $this->{$name} = User::factory()->boarder()->create(['name' => ucfirst($name)]);
        Tenancy::factory()->forUnit($room->units()->get()[$i])->movedInOn('2026-09-01')->create(['tenant_id' => $this->{$name}->id]);
    }
    RoomLeader::factory()->create(['room_id' => $room->id, 'user_id' => $this->kim->id]);
    $this->actingAs($this->kim, 'sanctum')->getJson('/api/v1/me/groups')->assertOk();
    $this->group = ContributionGroup::sole();
    $this->m = $this->group->members()->get()->keyBy('name');
    $this->g = "/api/v1/groups/{$this->group->id}";
});

afterEach(fn () => Date::setTestNow());

function addInternet(): array
{
    return test()->actingAs(test()->kim, 'sanctum')->postJson(test()->g.'/expenses', [
        'description' => 'Internet, November', 'amount_centavos' => 150000, 'due_on' => '2026-11-05',
    ])->assertCreated()->json('data');
}

it('lets the leader collect for a bill due later; members owe their share before it is paid', function () {
    $line = addInternet();
    expect($line)->toMatchArray([
        'kind' => 'collection', 'kind_label' => 'Collect first', 'due_on' => '2026-11-05',
        'paid_on' => null, 'is_settled' => false, 'is_overdue' => false,
        'collected_centavos' => 50000, // Kim's own share
        'can_mark_paid' => true,
    ]);

    // Ben and Ana owe Kim ₱500 each right away, before Kim pays anything.
    $this->getJson("{$this->g}/balances")->assertJsonPath('data.me.owed_to_me_centavos', 100000);

    // A member who is not the leader or a moderator cannot collect for the room.
    $this->actingAs($this->ben, 'sanctum')->postJson("{$this->g}/expenses", [
        'description' => 'Water dispenser', 'amount_centavos' => 30000, 'due_on' => '2026-11-01',
    ])->assertUnprocessable()->assertJsonValidationErrors('due_on');
});

it('shows what each member paid, then lets the collector mark the bill paid', function () {
    $line = addInternet();
    GroupRepayment::create(['group_id' => $this->group->id, 'from_member_id' => $this->m['Ben']->id, 'to_member_id' => $this->m['Kim']->id,
        'amount_centavos' => 50000, 'method' => 'cash', 'status' => RepaymentStatus::Confirmed]);

    $data = $this->actingAs($this->ana, 'sanctum')->getJson("{$this->g}/expenses")->assertOk()->json('data.0');
    expect($data['collected_centavos'])->toBe(100000)
        ->and($data['my_paid_centavos'])->toBe(0)
        ->and($data['can_mark_paid'])->toBeFalse()
        ->and(collect($data['shares'])->pluck('paid_centavos', 'name')->all())->toBe(['Kim' => 50000, 'Ben' => 50000, 'Ana' => 0]);

    // Past the due date and still not paid: overdue.
    Date::setTestNow(CarbonImmutable::parse('2026-11-06 10:00', 'Asia/Manila'));
    $this->getJson("{$this->g}/expenses")->assertJsonPath('data.0.is_overdue', true);

    $this->postJson("{$this->g}/expenses/{$line['id']}/paid", ['paid_on' => '2026-11-05'])->assertForbidden();
    $this->actingAs($this->kim, 'sanctum')->postJson("{$this->g}/expenses/{$line['id']}/paid", ['paid_on' => '2026-11-05'])
        ->assertOk()->assertJsonPath('data.paid_on', '2026-11-05')->assertJsonPath('data.is_settled', true)->assertJsonPath('data.is_overdue', false);
    $this->postJson("{$this->g}/expenses/{$line['id']}/paid", ['paid_on' => '2026-11-05'])->assertUnprocessable();

    // Ana still owes her share after the bill is paid.
    $this->actingAs($this->ana, 'sanctum')->getJson("{$this->g}/balances")->assertJsonPath('data.me.owe_centavos', 50000);
    expect(GroupExpense::find($line['id'])->kind)->toBe(GroupExpenseKind::Collection);
});

it('counts a payback against the oldest share first', function () {
    // Ana owes Kim ₱500 for the internet (due Nov 5) and ₱300 for an earlier expense of Kim's.
    $this->actingAs($this->kim, 'sanctum')->postJson("{$this->g}/expenses", [
        'description' => 'Rice cooker', 'amount_centavos' => 90000, 'spent_on' => '2026-10-10',
    ])->assertCreated();
    addInternet();
    GroupRepayment::create(['group_id' => $this->group->id, 'from_member_id' => $this->m['Ana']->id, 'to_member_id' => $this->m['Kim']->id,
        'amount_centavos' => 50000, 'method' => 'cash', 'status' => RepaymentStatus::Confirmed]);

    $lines = collect($this->actingAs($this->ana, 'sanctum')->getJson("{$this->g}/expenses")->json('data'))->keyBy('description');
    expect($lines['Rice cooker']['my_paid_centavos'])->toBe(30000)
        ->and($lines['Internet, November']['my_paid_centavos'])->toBe(20000);
});

it('shows my next shared bill to pay in on my home', function () {
    addInternet();

    $this->actingAs($this->ana, 'sanctum')->getJson('/api/v1/me/home')->assertOk()
        ->assertJsonPath('data.group.owe_centavos', 50000)
        ->assertJsonPath('data.group.next_due', ['description' => 'Internet, November', 'due_on' => '2026-11-05', 'my_left_centavos' => 50000, 'is_overdue' => false]);

    // The collector has nothing to pay in.
    $this->actingAs($this->kim, 'sanctum')->getJson('/api/v1/me/home')->assertJsonPath('data.group.next_due', null);
});
