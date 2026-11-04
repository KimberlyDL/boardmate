<?php

use App\Enums\BillKind;
use App\Enums\BillLineKind;
use App\Enums\GroupExpenseKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\ContributionGroup;
use App\Models\ExpenseShare;
use App\Models\GroupExpense;
use App\Models\GroupMember;
use App\Models\Payment;
use App\Models\Settlement;
use App\Models\Tenancy;
use App\Models\TenancyPayment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-11-04 10:00', 'Asia/Manila'));
    $this->owner = User::factory()->owner()->create();
    $this->house = makeProperty($this->owner);
    [$bed1, $bed2] = $this->house->units()->get();
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $this->ben = User::factory()->boarder()->create(['name' => 'Ben']);
    $this->stay = Tenancy::factory()->forUnit($bed1)->movedInOn('2026-09-01')->create(['tenant_id' => $this->kim->id]);
    $benStay = Tenancy::factory()->forUnit($bed2)->movedInOn('2026-09-01')->create(['tenant_id' => $this->ben->id]);

    // Move-in: deposit ₱4,000 and first rent ₱2,000 in September.
    TenancyPayment::factory()->create(['tenancy_id' => $this->stay->id, 'amount_centavos' => 400000, 'received_on' => '2026-09-01']);
    TenancyPayment::factory()->firstRent()->create(['tenancy_id' => $this->stay->id, 'amount_centavos' => 200000, 'received_on' => '2026-09-01']);

    // October: rent ₱2,000 + fixed water ₱300 and a ₱400 share; a ₱500 charge less a ₱100 correction.
    $monthly = Bill::factory()->forTenancy($this->stay)->create(['issued_on' => '2026-10-01', 'due_on' => '2026-10-01']);
    BillLine::factory()->forBill($monthly)->create(['amount_centavos' => 200000]);
    BillLine::factory()->forBill($monthly)->create(['kind' => BillLineKind::FixedUtility, 'amount_centavos' => 30000]);
    $share = Bill::factory()->forTenancy($this->stay)->create(['kind' => BillKind::Utility, 'issued_on' => '2026-10-12', 'due_on' => '2026-10-19']);
    BillLine::factory()->forBill($share)->create(['kind' => BillLineKind::UtilityShare, 'amount_centavos' => 40000]);
    $charge = Bill::factory()->forTenancy($this->stay)->create(['kind' => BillKind::Charge, 'issued_on' => '2026-10-10', 'due_on' => '2026-10-15']);
    BillLine::factory()->forBill($charge)->create(['kind' => BillLineKind::OneTime, 'amount_centavos' => 50000]);
    BillLine::factory()->forBill($charge)->create(['kind' => BillLineKind::Adjustment, 'amount_centavos' => -10000]);

    // Paid: ₱2,300 in October; a deposit use, a pending report and someone else's payment never count.
    Payment::factory()->forPayer($this->house, $this->kim)->confirmed()->create(['amount_centavos' => 230000, 'paid_on' => '2026-10-03']);
    Payment::factory()->forPayer($this->house, $this->kim)->confirmed()->create(['amount_centavos' => 30000, 'paid_on' => '2026-10-20', 'method' => PaymentMethod::Deposit]);
    Payment::factory()->forPayer($this->house, $this->kim)->create(['amount_centavos' => 70000, 'paid_on' => '2026-11-02', 'status' => PaymentStatus::Pending]);
    Payment::factory()->forPayer($this->house, $this->ben)->confirmed()->create(['amount_centavos' => 99000, 'paid_on' => '2026-10-03']);

    // Room group: Ben paid the ₱1,000 internet (Kim's share ₱500); the ₱400 share bill billed to Kim is already in her bills.
    $group = ContributionGroup::factory()->forRoom($this->stay->room)->create();
    $kimMember = GroupMember::factory()->forGroup($group)->create(['user_id' => $this->kim->id, 'tenancy_id' => $this->stay->id]);
    $benMember = GroupMember::factory()->forGroup($group)->create(['user_id' => $this->ben->id, 'tenancy_id' => $benStay->id]);
    $internet = GroupExpense::factory()->paidBy($benMember)->create(['amount_centavos' => 100000, 'spent_on' => '2026-10-05']);
    ExpenseShare::create(['expense_id' => $internet->id, 'member_id' => $kimMember->id, 'amount_centavos' => 50000]);
    ExpenseShare::create(['expense_id' => $internet->id, 'member_id' => $benMember->id, 'amount_centavos' => 50000]);
    $posted = GroupExpense::factory()->paidBy($kimMember)->create(['kind' => GroupExpenseKind::OwnerBill, 'bill_id' => $share->id, 'amount_centavos' => 40000, 'spent_on' => '2026-10-12']);
    ExpenseShare::create(['expense_id' => $posted->id, 'member_id' => $kimMember->id, 'amount_centavos' => 20000]);
    ExpenseShare::create(['expense_id' => $posted->id, 'member_id' => $benMember->id, 'amount_centavos' => 20000]);
});

afterEach(fn () => Date::setTestNow());

it('adds up what a boarder was billed and paid, month by month', function () {
    $data = $this->actingAs($this->kim, 'sanctum')->getJson('/api/v1/me/spending')->assertOk()->json('data');
    $month = fn (string $m) => collect($data['months'])->firstWhere('month', $m);

    expect($data['period'])->toBe(['from' => '2025-12-01', 'to' => '2026-11-30'])
        ->and($data['months'])->toHaveCount(12)
        ->and($month('2026-09'))->toBe(['month' => '2026-09', 'rent' => 200000, 'utilities' => 0, 'other' => 0, 'paid' => 200000, 'group' => 0])
        ->and($month('2026-10'))->toBe(['month' => '2026-10', 'rent' => 200000, 'utilities' => 70000, 'other' => 50000, 'paid' => 230000, 'group' => 50000])
        ->and($month('2026-11')['other'])->toBe(-10000)   // the correction counts on the day it was added (today)
        ->and($data['totals'])->toBe(['rent' => 400000, 'utilities' => 70000, 'other' => 40000, 'billed' => 510000, 'paid' => 430000, 'group' => 50000])
        ->and($data['deposits'])->toBe(['paid' => 400000, 'held' => 400000, 'refunded' => 0, 'kept' => 0]);
});

it('shows a settled deposit as refunded and kept', function () {
    Settlement::factory()->forTenancy($this->stay)->create(['deposit_held_centavos' => 400000, 'refund_centavos' => 350000, 'kept_centavos' => 50000, 'refunded_on' => '2026-11-03']);

    $this->actingAs($this->kim, 'sanctum')->getJson('/api/v1/me/spending')->assertOk()
        ->assertJsonPath('data.deposits', ['paid' => 400000, 'held' => 0, 'refunded' => 350000, 'kept' => 50000]);
});

it('shows only my own money', function () {
    $this->actingAs($this->ben, 'sanctum')->getJson('/api/v1/me/spending')->assertOk()
        ->assertJsonPath('data.totals', ['rent' => 0, 'utilities' => 0, 'other' => 0, 'billed' => 0, 'paid' => 99000, 'group' => 70000]);   // ₱500 internet + ₱200 of Kim's share bill

    $this->actingAs(User::factory()->boarder()->create(), 'sanctum')->getJson('/api/v1/me/spending')->assertOk()
        ->assertJsonPath('data.totals.paid', 0);
});
