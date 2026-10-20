<?php

use App\Enums\BillKind;
use App\Enums\GroupExpenseKind;
use App\Enums\NotificationEvent;
use App\Enums\RentalMode;
use App\Enums\RepaymentStatus;
use App\Enums\SplitMethod;
use App\Models\Bill;
use App\Models\ContributionGroup;
use App\Models\GroupExpense;
use App\Models\GroupMember;
use App\Models\GroupRepayment;
use App\Models\RoomLeader;
use App\Models\RoomOccupant;
use App\Models\Tenancy;
use App\Models\User;
use App\Models\UtilityBill;
use App\Services\Billing\BillingService;
use App\Services\Groups\GroupSplitter;
use App\Services\Notifications\DormLinkNotification;
use App\Services\Properties\PropertySetup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    Date::setTestNow(CarbonImmutable::parse('2026-09-01 10:00', 'Asia/Manila'));

    $this->owner = User::factory()->owner()->create();
    // Room A: two bedspaces. Room B: rented whole (₱4,500).
    $this->property = makeProperty($this->owner, ['units' => ['count' => 2, 'rent_centavos' => 180000]]);
    $this->roomA = $this->property->rooms()->first();
    $this->roomA->update(['door_no' => 'A']);
    $this->roomB = app(PropertySetup::class)->createRoom($this->property, RentalMode::Whole, ['capacity' => 3, 'rent_centavos' => 450000], $this->owner, ['door_no' => 'B']);

    [$bed1, $bed2] = $this->roomA->units()->get();
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $this->ben = User::factory()->boarder()->create(['name' => 'Ben']);
    $this->leo = User::factory()->boarder()->create(['name' => 'Leo']);
    Tenancy::factory()->forUnit($bed1)->movedInOn('2026-09-01')->create(['tenant_id' => $this->kim->id]);
    Tenancy::factory()->forUnit($bed2)->movedInOn('2026-09-25')->create(['tenant_id' => $this->ben->id]);
    $this->leoStay = Tenancy::factory()->forUnit($this->roomB->units()->first())->movedInOn('2026-09-01')->create(['tenant_id' => $this->leo->id]);
    RoomOccupant::factory()->create(['room_id' => $this->roomB->id, 'tenancy_id' => $this->leoStay->id, 'user_id' => $this->leo->id, 'name' => 'Leo', 'joined_on' => '2026-09-01']);
    RoomOccupant::factory()->create(['room_id' => $this->roomB->id, 'tenancy_id' => $this->leoStay->id, 'name' => 'Ericka', 'joined_on' => '2026-09-10']);
    RoomLeader::factory()->create(['room_id' => $this->roomA->id, 'user_id' => $this->kim->id]);
    RoomLeader::factory()->create(['room_id' => $this->roomB->id, 'user_id' => $this->leo->id]);
    $this->meralco = $this->property->utilityAccounts()->create(['type' => 'electricity', 'name' => 'Meralco', 'method' => 'actual_bill', 'billed_by' => 'owner']);

    Date::setTestNow(CarbonImmutable::parse('2026-10-12 10:00', 'Asia/Manila'));
});

afterEach(fn () => Date::setTestNow());

function meralco(int $total = 180000)
{
    return test()->actingAs(test()->owner, 'sanctum')->postJson('/api/v1/properties/'.test()->property->id.'/utility-bills', [
        'utility_account_id' => test()->meralco->id, 'coverage_from_on' => '2026-09-10', 'coverage_to_on' => '2026-10-09', 'total_centavos' => $total,
    ]);
}

/** member name => centavos for a line */
function sharesOf(GroupExpense $line): array
{
    return $line->shares()->with('member')->get()->mapWithKeys(fn ($s) => [$s->member->name => $s->amount_centavos])->all();
}

it('posts each room\'s Meralco share to its group, split by days stayed (SC-06), and tells the others their share', function () {
    meralco()->assertCreated();

    // Room A (₱771.43, billed to Kim): Kim 30 days, Ben 15 days (from Sep 25).
    $a = GroupExpense::whereHas('group', fn ($q) => $q->where('room_id', $this->roomA->id))->sole();
    expect($a->kind)->toBe(GroupExpenseKind::OwnerBill)
        ->and($a->amount_centavos)->toBe(77143)
        ->and($a->paidBy->name)->toBe('Kim')
        ->and($a->split_method)->toBe(SplitMethod::OccupantDays)
        ->and(sharesOf($a))->toBe(['Kim' => 51429, 'Ben' => 25714]);

    // Room B (₱1,028.57, billed to Leo): Leo and Ericka 30 days each.
    $b = GroupExpense::whereHas('group', fn ($q) => $q->where('room_id', $this->roomB->id))->sole();
    expect(sharesOf($b))->toBe(['Leo' => 51429, 'Ericka' => 51428]);

    Notification::assertSentTo($this->ben, DormLinkNotification::class,
        fn ($n) => $n->event === NotificationEvent::GroupLinePosted && $n->data['share'] === '₱257.14' && $n->data['owed_to'] === 'Kim');
    Notification::assertNotSentTo($this->kim, DormLinkNotification::class, fn ($n) => $n->event === NotificationEvent::GroupLinePosted);
});

it('posts a whole room\'s monthly bill, not a bedspacer\'s, and never a bill from before the group', function () {
    // An old bill from before the group existed stays out.
    Bill::factory()->forTenancy($this->leoStay)->create(['kind' => BillKind::Charge, 'issued_on' => '2026-09-05']);
    app(BillingService::class)->issueDuePeriodicBills(CarbonImmutable::parse('2026-10-01', 'Asia/Manila'));

    $lines = GroupExpense::with('group')->get();
    expect($lines)->toHaveCount(1)
        ->and($lines[0]->group->room_id)->toBe($this->roomB->id)
        ->and($lines[0]->description)->toContain('Monthly bill');
    expect(Bill::where('kind', BillKind::Periodic)->count())->toBe(2); // Leo's (posted) and Kim's bedspace bill (not posted); Ben's first is Oct 25
});

it('lets the leader or a moderator change a split: weights, fixed amounts, equal (SC-13)', function () {
    meralco()->assertCreated();
    $line = GroupExpense::whereHas('group', fn ($q) => $q->where('room_id', $this->roomA->id))->sole();
    $group = $line->group;
    [$kim, $ben] = [$group->members()->where('name', 'Kim')->first(), $group->members()->where('name', 'Ben')->first()];
    $url = "/api/v1/groups/{$group->id}/expenses/{$line->id}/split";

    $this->actingAs($this->ben, 'sanctum')->putJson($url, ['method' => 'equal'])->assertForbidden();
    $this->actingAs($this->kim, 'sanctum')->putJson($url, ['method' => 'weights', 'weights' => [$kim->id => 1, $ben->id => '0.5']])->assertOk()
        ->assertJsonPath('data.shares.0.amount_centavos', 51429)
        ->assertJsonPath('data.split_method', 'weights');
    $this->putJson($url, ['method' => 'custom', 'amounts' => [$kim->id => 50000, $ben->id => 20000]])
        ->assertUnprocessable()->assertJsonValidationErrors(['amounts']);
    $this->putJson($url, ['method' => 'custom', 'amounts' => [$kim->id => 57143, $ben->id => 20000]])->assertOk()->assertJsonPath('data.my_share_centavos', 57143);
    $this->putJson($url, ['method' => 'equal'])->assertOk();
    expect(sharesOf($line->fresh()))->toBe(['Kim' => 38572, 'Ben' => 38571]);

    // ₱1,000 three ways: ₱333.34, ₱333.33, ₱333.33 (SC-13).
    $ana = GroupMember::factory()->forGroup($group)->create(['name' => 'Ana']);
    $shares = app(GroupSplitter::class)->split(100000, SplitMethod::Equal, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'), $group->members()->get(), $kim->id);
    expect(array_values($shares))->toBe([33334, 33333, 33333]);
});

it('follows an owner correction: re-split while unpaid, a correction line once someone has paid back', function () {
    meralco()->assertCreated();
    $line = GroupExpense::whereHas('group', fn ($q) => $q->where('room_id', $this->roomA->id))->sole();
    $old = UtilityBill::sole();

    // ₱1,800 → ₱2,250 (room A ₱964.29), nobody has paid back yet: the line follows.
    $this->postJson("/api/v1/utility-bills/{$old->id}/correct", ['total_centavos' => 225000, 'reason' => 'Misread meter'])->assertOk();
    expect($line->fresh()->amount_centavos)->toBe(96429)
        ->and(array_sum(sharesOf($line->fresh())))->toBe(96429);

    // Ben pays Kim back; another correction becomes its own line.
    [$kim, $ben] = [$line->group->members()->where('name', 'Kim')->first(), $line->group->members()->where('name', 'Ben')->first()];
    GroupRepayment::factory()->between($ben, $kim)->create(['amount_centavos' => 32143, 'status' => RepaymentStatus::Confirmed]);
    $this->putJson("/api/v1/groups/{$line->group_id}/expenses/{$line->id}/split", ['method' => 'equal'])->assertForbidden(); // owner is not in the group
    $this->actingAs($this->kim, 'sanctum')->putJson("/api/v1/groups/{$line->group_id}/expenses/{$line->id}/split", ['method' => 'equal'])
        ->assertUnprocessable()->assertJsonValidationErrors(['method']);

    $latest = UtilityBill::latest('id')->first();
    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/utility-bills/{$latest->id}/correct", ['total_centavos' => 180000, 'reason' => 'Back to the right total'])->assertOk();
    $fix = GroupExpense::where('kind', GroupExpenseKind::Adjustment)->sole();
    expect($fix->amount_centavos)->toBe(-19286)
        ->and($fix->adjusts_expense_id)->toBe($line->id)
        ->and(array_sum(sharesOf($fix)))->toBe(-19286)
        ->and($line->fresh()->amount_centavos)->toBe(96429);
});

it('shows members every share, and the owner only their own bills without shares', function () {
    meralco()->assertCreated();
    $group = ContributionGroup::where('room_id', $this->roomA->id)->sole();
    GroupExpense::factory()->paidBy($group->members()->where('name', 'Ben')->first())->create(['description' => 'Internet']);

    $this->actingAs($this->ben, 'sanctum')->getJson("/api/v1/groups/{$group->id}/expenses")->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.view', 'member');
    $line = collect($this->getJson("/api/v1/groups/{$group->id}/expenses")->json('data'))->firstWhere('kind', 'owner_bill');
    expect($line['my_share_centavos'])->toBe(25714)->and($line['is_locked'])->toBeTrue();

    $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/groups/{$group->id}/expenses")->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.view', 'staff')
        ->assertJsonPath('data.0.kind', 'owner_bill')
        ->assertJsonMissingPath('data.0.shares');
});
