<?php

use App\Enums\BillKind;
use App\Enums\MaintenanceStatus;
use App\Enums\NoticeStatus;
use App\Enums\VisitorStatus;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\ContributionGroup;
use App\Models\ExpenseShare;
use App\Models\GroupExpense;
use App\Models\GroupMember;
use App\Models\HouseRuleVersion;
use App\Models\LateEntryRequest;
use App\Models\MaintenanceRequest;
use App\Models\Tenancy;
use App\Models\TenancyNotice;
use App\Models\User;
use App\Models\Visitor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Manila'));
    $this->owner = User::factory()->owner()->create();
    $this->property = makeProperty($this->owner, ['units' => ['count' => 2, 'rent_centavos' => 200000]]);
    $this->property->update(['emergency_numbers' => 'Barangay 8123 4567 · Fire 160']);
    [$bed1, $bed2] = $this->property->units()->get();
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $this->ben = User::factory()->boarder()->create(['name' => 'Ben']);
    $this->stay = Tenancy::factory()->forUnit($bed1)->movedInOn('2026-09-01')->create(['tenant_id' => $this->kim->id]);
    $this->benStay = Tenancy::factory()->forUnit($bed2)->movedInOn('2026-09-01')->create(['tenant_id' => $this->ben->id]);
});

afterEach(fn () => Date::setTestNow());

it('gives a tenant their stay, money, requests and what needs them', function () {
    foreach ([[50000, '2026-10-15'], [200000, '2026-10-25']] as [$amount, $due]) {
        $bill = Bill::factory()->forTenancy($this->stay)->create(['kind' => BillKind::Periodic, 'issued_on' => '2026-10-01', 'due_on' => $due]);
        BillLine::factory()->forBill($bill)->create(['amount_centavos' => $amount]);
    }
    MaintenanceRequest::factory()->forTenancy($this->stay)->create();
    MaintenanceRequest::factory()->forTenancy($this->stay)->status(MaintenanceStatus::Resolved)->create();
    LateEntryRequest::factory()->forTenancy($this->stay)->create(['on_date' => '2026-10-22']);
    Visitor::factory()->forTenancy($this->stay)->overnight(1, VisitorStatus::Pending)->create(['visit_on' => '2026-10-22']);
    TenancyNotice::factory()->forTenancy($this->stay)->create(['end_on' => '2026-11-30', 'status' => NoticeStatus::Pending]);
    HouseRuleVersion::factory()->forProperty($this->property)->published()->create();

    $data = $this->actingAs($this->kim, 'sanctum')->getJson('/api/v1/me/home')->assertOk()->json('data');
    expect($data['stay'])->toMatchArray([
        'property' => ['id' => $this->property->id, 'name' => $this->property->name],
        'emergency_numbers' => 'Barangay 8123 4567 · Fire 160',
        'leaving' => ['end_on' => '2026-11-30', 'approved' => false],
    ])
        ->and($data['money'])->toMatchArray(['owed_centavos' => 250000, 'credit_centavos' => 0, 'overdue_centavos' => 50000])
        ->and($data['money']['next_bill']['due_on'])->toBe('2026-10-15')
        ->and($data['requests'])->toBe(['repairs_open' => 1, 'repairs_to_confirm' => 1, 'late_entries_waiting' => 1, 'visitors_waiting' => 1])
        ->and($data['house_rules_to_accept'])->toBeTrue()
        ->and($data['group'])->toBeNull(); // no room group yet
});

it('shows the room leader who owes them in the room group', function () {
    $group = ContributionGroup::factory()->forRoom($this->stay->room)->create();
    $kim = GroupMember::factory()->forGroup($group)->admin()->create(['user_id' => $this->kim->id, 'tenancy_id' => $this->stay->id, 'name' => 'Kim']);
    $ben = GroupMember::factory()->forGroup($group)->create(['user_id' => $this->ben->id, 'tenancy_id' => $this->benStay->id, 'name' => 'Ben']);
    $line = GroupExpense::factory()->paidBy($kim)->create(['amount_centavos' => 60000]);
    ExpenseShare::create(['expense_id' => $line->id, 'member_id' => $kim->id, 'amount_centavos' => 30000]);
    ExpenseShare::create(['expense_id' => $line->id, 'member_id' => $ben->id, 'amount_centavos' => 30000]);

    $this->actingAs($this->kim, 'sanctum')->getJson('/api/v1/me/home')->assertOk()
        ->assertJsonPath('data.group.is_leader', true)
        ->assertJsonPath('data.group.owed_to_me_centavos', 30000)
        ->assertJsonPath('data.group.members_owing_me', [['name' => 'Ben', 'amount_centavos' => 30000]]);
    $this->actingAs($this->ben, 'sanctum')->getJson('/api/v1/me/home')
        ->assertJsonPath('data.group.owe_centavos', 30000)
        ->assertJsonPath('data.group.members_owing_me', null);
});

it('has nothing but notifications for someone not staying anywhere', function () {
    $this->actingAs(User::factory()->boarder()->create(), 'sanctum')->getJson('/api/v1/me/home')->assertOk()
        ->assertJsonPath('data.stay', null)
        ->assertJsonPath('data.money', null)
        ->assertJsonPath('data.house_rules_to_accept', false)
        ->assertJsonPath('data.unread_notifications', 0);
});
