<?php

use App\Enums\BillKind;
use App\Enums\CaretakerAccessLevel;
use App\Enums\MaintenanceUrgency;
use App\Enums\UnitStatus;
use App\Enums\VisitorStatus;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\BookingApplication;
use App\Models\LateEntryRequest;
use App\Models\MaintenanceRequest;
use App\Models\Payment;
use App\Models\Tenancy;
use App\Models\User;
use App\Models\Visitor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Manila'));
    $this->owner = User::factory()->owner()->create();
    $this->manager = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Manager)->create();
    $this->collector = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Collector)->create();
    $this->property = makeProperty($this->owner, ['units' => ['count' => 3, 'rent_centavos' => 200000]]);
    foreach ([$this->manager, $this->collector] as $c) {
        assignCaretaker($this->property, $c, $c === $this->manager ? 'manager' : 'collector');
    }
    $this->url = "/api/v1/properties/{$this->property->id}/dashboard";
});

afterEach(fn () => Date::setTestNow());

/** A bill with one line of $amount, due on $due. */
function dashBill(Tenancy $stay, int $amount, string $due, BillKind $kind = BillKind::Periodic): Bill
{
    $bill = Bill::factory()->forTenancy($stay)->create(['kind' => $kind, 'issued_on' => '2026-10-01', 'due_on' => $due]);
    BillLine::factory()->forBill($bill)->create(['amount_centavos' => $amount]);

    return $bill;
}

it('adds up the property from the same rows as the rest of the app', function () {
    [$bed1, $bed2, $bed3] = $this->property->units()->get();
    $kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $stay = Tenancy::factory()->forUnit($bed1)->movedInOn('2026-09-01')->create(['tenant_id' => $kim->id]);
    $bed1->forceFill(['status' => UnitStatus::Occupied])->save();
    $bed2->forceFill(['status' => UnitStatus::Reserved])->save();
    $bed3->forceFill(['not_ready' => true])->save();

    dashBill($stay, 200000, '2026-10-24');                       // due this week
    dashBill($stay, 200000, '2026-10-30');                       // later: not this week
    dashBill($stay, 50000, '2026-10-15');                        // overdue
    dashBill($stay, 30000, '2026-10-10', BillKind::Utility);     // the room's shared bill, overdue
    Payment::factory()->forPayer($this->property, $kim)->confirmed()->create(['amount_centavos' => 100000, 'paid_on' => '2026-10-02']);
    Payment::factory()->forPayer($this->property, $kim)->confirmed()->create(['amount_centavos' => 70000, 'paid_on' => '2026-09-30']); // last month
    Payment::factory()->forPayer($this->property, $kim)->create(); // a proof to confirm
    (new BookingApplication(['planned_move_in_on' => '2026-11-01', 'contact_phone' => '0917 123 4567']))->forceFill(['property_id' => $this->property->id, 'boarder_id' => User::factory()->boarder()->create()->id, 'status' => 'pending'])->save();
    MaintenanceRequest::factory()->forTenancy($stay)->create(['urgency' => MaintenanceUrgency::Urgent]);
    MaintenanceRequest::factory()->forTenancy($stay)->create();
    Visitor::factory()->forTenancy($stay)->overnight(1, VisitorStatus::Pending)->create(['visit_on' => '2026-10-21']);
    Visitor::factory()->forTenancy($stay)->create(['visit_on' => '2026-10-20']);
    LateEntryRequest::factory()->forTenancy($stay)->create(['on_date' => '2026-10-21']);

    $data = $this->actingAs($this->owner, 'sanctum')->getJson($this->url)->assertOk()->json('data');
    expect($data['occupancy'])->toBe(['rooms' => 1, 'places' => 3, 'occupied' => 1, 'reserved' => 1, 'available' => 0, 'not_ready' => 1])
        ->and($data['due_this_week'])->toBe(['bills' => 1, 'centavos' => 200000])
        ->and($data['overdue'])->toBe(['payers' => 1, 'centavos' => 80000])
        ->and($data['collected_this_month'])->toBe(100000)
        ->and($data['applications_waiting'])->toBe(1)
        ->and($data['proofs_waiting'])->toBe(1)
        ->and($data['repairs'])->toBe(['open' => 2, 'urgent' => 1])
        ->and($data['house_operations'])->toBe(['visitors_today' => 1, 'overnight_waiting' => 1, 'late_entries_waiting' => 1, 'repeated_late' => 0])
        ->and($data['unpaid_shared_bills'])->toHaveCount(1)
        ->and($data['unpaid_shared_bills'][0])->toMatchArray(['billed_to' => 'Kim', 'centavos' => 30000, 'overdue' => true]);
});

it('hides what a caretaker may not see, and keeps strangers out', function () {
    $this->actingAs($this->collector, 'sanctum')->getJson($this->url)->assertOk()
        ->assertJsonPath('data.collected_this_month', null)   // owner and Managers only
        ->assertJsonPath('data.applications_waiting', null)
        ->assertJsonPath('data.proofs_waiting', 0)
        ->assertJsonPath('data.overdue.payers', 0);
    $this->actingAs($this->manager, 'sanctum')->getJson($this->url)->assertJsonPath('data.collected_this_month', 0);

    $this->actingAs(User::factory()->owner()->create(), 'sanctum')->getJson($this->url)->assertNotFound();
    $this->actingAs(User::factory()->boarder()->create(), 'sanctum')->getJson($this->url)->assertNotFound();
});

it('gives every property at a glance, and the owner their latest activity', function () {
    makeProperty($this->owner, ['details' => ['name' => 'Annex']]);

    $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/me/dashboard')->assertOk()
        ->assertJsonCount(2, 'data.properties')
        ->assertJsonPath('data.properties.0.property.name', 'Annex')
        ->assertJsonPath('data.properties.0.occupancy.places', 3);
    expect($this->getJson('/api/v1/me/dashboard')->json('data.recent_activity'))->toBeArray();

    // A caretaker sees only the properties assigned to them, and no activity log.
    $this->actingAs($this->collector, 'sanctum')->getJson('/api/v1/me/dashboard')->assertOk()
        ->assertJsonCount(1, 'data.properties')
        ->assertJsonPath('data.recent_activity', null);
});
