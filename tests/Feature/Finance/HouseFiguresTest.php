<?php

use App\Enums\LateEntryStatus;
use App\Enums\MaintenanceCategory;
use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceUrgency;
use App\Models\CurfewViolation;
use App\Models\LateEntryRequest;
use App\Models\MaintenanceRequest;
use App\Models\Tenancy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Manila'));
    $this->owner = User::factory()->owner()->create();
    $this->house = makeProperty($this->owner, ['units' => ['count' => 2, 'rent_centavos' => 200000]]);
    [$bed1, $bed2] = $this->house->units()->get();
    $this->kimStay = Tenancy::factory()->forUnit($bed1)->movedInOn('2026-09-01')
        ->create(['tenant_id' => User::factory()->boarder()->create(['name' => 'Kim'])->id]);
    $this->benStay = Tenancy::factory()->forUnit($bed2)->movedInOn('2026-09-01')
        ->create(['tenant_id' => User::factory()->boarder()->create(['name' => 'Ben'])->id]);

    // Repairs in October: one fixed in 3 days, one urgent still open, one cancelled; one in a common area; one in September.
    $repair = fn (array $attrs) => MaintenanceRequest::factory()->forTenancy($this->kimStay)->create($attrs);
    $repair(['created_at' => '2026-10-02 09:00', 'resolved_at' => '2026-10-05 09:00', 'status' => MaintenanceStatus::Resolved]);
    $repair(['created_at' => '2026-10-10 09:00', 'category' => MaintenanceCategory::Electrical, 'urgency' => MaintenanceUrgency::Urgent]);
    $repair(['created_at' => '2026-10-12 09:00', 'cancelled_at' => '2026-10-13 09:00', 'status' => MaintenanceStatus::Cancelled]);
    $repair(['created_at' => '2026-10-03 09:00', 'category' => MaintenanceCategory::Other, 'room_id' => null, 'tenancy_id' => null]);
    $repair(['created_at' => '2026-09-10 09:00', 'resolved_at' => '2026-09-11 09:00', 'status' => MaintenanceStatus::Resolved]);

    // Late entries: Kim asks (approved, declined, waiting); Ben comes back late three times without asking.
    $ask = fn (string $on, LateEntryStatus $status) => LateEntryRequest::factory()->forTenancy($this->kimStay)->create(['on_date' => $on, 'status' => $status]);
    $ask('2026-10-03', LateEntryStatus::Approved);
    $ask('2026-10-04', LateEntryStatus::Declined);
    $ask('2026-10-22', LateEntryStatus::Pending);
    $ask('2026-09-15', LateEntryStatus::Approved);
    foreach (['2026-10-05', '2026-10-06', '2026-10-07', '2026-09-20'] as $night) {
        CurfewViolation::create(['property_id' => $this->house->id, 'tenancy_id' => $this->benStay->id, 'on_date' => $night]);
    }
    CurfewViolation::create(['property_id' => $this->house->id, 'tenancy_id' => $this->kimStay->id, 'on_date' => '2026-10-08']);
});

afterEach(fn () => Date::setTestNow());

it('counts repairs and late entries in the period, with the period before', function () {
    $house = $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/properties/{$this->house->id}/finance")
        ->assertOk()->json('data.house');

    expect($house['repairs'])->toBe([
        'reported' => 4,
        'fixed' => 1,
        'cancelled' => 1,
        'average_days_to_fix' => 3,
        'open_now' => 2,
        'urgent_open_now' => 1,
        'by_kind' => [
            ['kind' => 'plumbing', 'label' => 'Plumbing', 'count' => 2],
            ['kind' => 'electrical', 'label' => 'Electrical', 'count' => 1],
            ['kind' => 'other', 'label' => 'Other', 'count' => 1],
        ],
    ])
        ->and($house['late_entries']['requests'])->toBe(['approved' => 1, 'declined' => 1, 'waiting' => 1, 'cancelled' => 0])
        ->and($house['late_entries']['curfew_violations'])->toBe(4)
        ->and($house['late_entries']['repeated'])->toBe([['stay' => ['id' => $this->benStay->id, 'tenant' => 'Ben', 'room' => $this->benStay->room->label()], 'nights' => 3]])
        ->and($house['previous'])->toBe(['repairs_reported' => 1, 'repairs_fixed' => 1, 'late_requests' => 1, 'curfew_violations' => 1]);

    // Every property gives the same; a period without anything gives zeros.
    $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/finance')->assertOk()
        ->assertJsonPath('data.house.repairs.reported', 4);
    $quiet = $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/finance?from=2026-08-01&to=2026-08-31')->assertOk()->json('data.house');
    expect($quiet['repairs']['reported'])->toBe(0)
        ->and($quiet['repairs']['average_days_to_fix'])->toBeNull()
        ->and($quiet['repairs']['open_now'])->toBe(2)   // open now, whatever the period
        ->and($quiet['late_entries']['repeated'])->toBe([]);
});

it('leaves common-area repairs out of a room', function () {
    $room = $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/rooms/{$this->kimStay->room_id}/finance")
        ->assertOk()->json('data.house');

    expect($room['repairs']['reported'])->toBe(3)
        ->and($room['repairs']['open_now'])->toBe(1)
        ->and($room['late_entries']['curfew_violations'])->toBe($this->kimStay->room_id === $this->benStay->room_id ? 4 : 1);
});

it('adds repairs and late entries to the PDF statement', function () {
    $url = $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/properties/{$this->house->id}/finance/pdf-link")->assertOk()->json('data.url');
    auth()->forgetGuards();

    $pdf = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr($pdf->getContent(), 0, 4))->toBe('%PDF');
});
