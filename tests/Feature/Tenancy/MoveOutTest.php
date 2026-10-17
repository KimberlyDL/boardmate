<?php

use App\Enums\BillKind;
use App\Enums\CaretakerAccessLevel;
use App\Enums\NotificationEvent;
use App\Enums\RentalMode;
use App\Enums\TenancyStatus;
use App\Enums\UnitStatus;
use App\Models\Bill;
use App\Models\RoomLeader;
use App\Models\RoomOccupant;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\MeteredBillService;
use App\Services\Notifications\DormLinkNotification;
use App\Services\Pricing\PriceBook;
use App\Services\Properties\PropertySetup;
use App\Services\Properties\UnitStatusService;
use App\Services\Tenancies\MoveOutService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    // Property, rent ₱1,800 and water ₱300 a bedspace exist from Sep 1.
    Date::setTestNow(CarbonImmutable::parse('2026-09-01 10:00', 'Asia/Manila'));
    $this->owner = User::factory()->owner()->create();
    $this->property = makeProperty($this->owner, ['units' => ['count' => 2, 'rent_centavos' => 180000]]);
    $water = $this->property->utilityAccounts()->create(['type' => 'water', 'name' => 'Water', 'method' => 'fixed_per_bedspace', 'billed_by' => 'owner']);
    app(PriceBook::class)->set($water, 30000, null, $this->owner);

    $this->unit = $this->property->units()->first();
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $this->tenancy = Tenancy::factory()->forUnit($this->unit)->movedInOn('2026-09-25')->create(['tenant_id' => $this->kim->id]);
    app(UnitStatusService::class)->move($this->unit, UnitStatus::Occupied);
    app(BillingService::class)->issueDuePeriodicBills(CarbonImmutable::parse('2026-10-25', 'Asia/Manila')); // rent Oct 26 – Nov 25 + water Sep 26 – Oct 25

    $this->collector = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Collector)->create();
    assignCaretaker($this->property, $this->collector, 'collector');
    $this->t = "/api/v1/tenancies/{$this->tenancy->id}";
});

afterEach(fn () => Date::setTestNow());

it('records a boarder who left without notice, ends the stay and bills the last month\'s water (SC-11)', function () {
    Date::setTestNow(CarbonImmutable::parse('2026-11-03 10:00', 'Asia/Manila'));
    $this->actingAs($this->collector, 'sanctum');

    $this->postJson("{$this->t}/move-out", ['moved_out_on' => '2026-10-31'])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $this->postJson("{$this->t}/move-out", ['moved_out_on' => '2026-11-04', 'reason' => 'X'])->assertUnprocessable()->assertJsonValidationErrors(['moved_out_on']);
    $this->postJson("{$this->t}/move-out", ['moved_out_on' => '2026-10-31', 'reason' => 'Left without notice; keys under the door'])->assertOk()
        ->assertJsonPath('data.status', 'ended')
        ->assertJsonPath('data.status_label', 'Moved out')
        ->assertJsonPath('data.end_on', '2026-10-31')
        ->assertJsonPath('data.moved_out_on', '2026-10-31')
        ->assertJsonPath('data.unit_status', 'available')
        ->assertJsonPath('data.final_bill.total_centavos', 30000);
    $this->postJson("{$this->t}/move-out", ['moved_out_on' => '2026-10-31'])->assertUnprocessable();

    $final = Bill::where('kind', BillKind::Final)->with('lines')->sole();
    expect($final->period_start_on->toDateString())->toBe('2026-10-26')
        ->and($final->lines->pluck('description')->all())->toBe(['Water, Oct 26 – Nov 25, 2026'])
        ->and($final->due_on->toDateString())->toBe('2026-11-03')
        ->and($this->tenancy->fresh()->moved_out_by)->toBe($this->collector->id);
    Notification::assertSentTo($this->kim, DormLinkNotification::class,
        fn ($n) => $n->event === NotificationEvent::MovedOut && $n->data['final_bill'] === '₱300.00');

    // No more rent after the move-out; the unit can be rented again.
    expect(app(BillingService::class)->issueDuePeriodicBills(CarbonImmutable::parse('2026-12-31', 'Asia/Manila'))['issued'])->toBe(0)
        ->and(Tenancy::current()->count())->toBe(0);

    // A charge can still be added to the ended tenancy (C8: a late utility bill, damage).
    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/properties/{$this->property->id}/charges", [
        'tenancy_id' => $this->tenancy->id, 'amount_centavos' => 25000, 'description' => 'Broken lock', 'reason' => 'Found at move-out',
    ])->assertCreated();
});

it('flags an overstay the day after the end date, once, and clears it on extension or move-out (SC-29)', function () {
    Date::setTestNow(CarbonImmutable::parse('2026-11-01 10:00', 'Asia/Manila'));
    $this->actingAs($this->owner, 'sanctum')->putJson("{$this->t}/end-date", ['end_on' => '2026-11-10', 'reason' => 'Going home'])->assertOk();
    $flag = fn (string $day) => app(MoveOutService::class)->flagOverstays(CarbonImmutable::parse($day, 'Asia/Manila'));

    expect($flag('2026-11-10'))->toBe(0)
        ->and($flag('2026-11-11'))->toBe(1)
        ->and($flag('2026-11-12'))->toBe(0)
        ->and($this->unit->fresh()->status)->toBe(UnitStatus::Overstaying);
    Notification::assertSentTo($this->collector, DormLinkNotification::class,
        fn ($n) => $n->event === NotificationEvent::TenancyOverstaying && $n->data['end_on'] === 'Nov 10, 2026');
    Date::setTestNow(CarbonImmutable::parse('2026-11-12 10:00', 'Asia/Manila'));
    $this->getJson($this->t)->assertJsonPath('data.is_overstaying', true);

    // Extended: back to Leaving.
    $this->putJson("{$this->t}/end-date", ['end_on' => '2026-11-14', 'reason' => 'Bus ticket is for the 14th'])->assertOk()->assertJsonPath('data.unit_status', 'leaving');

    Date::setTestNow(CarbonImmutable::parse('2026-11-16 10:00', 'Asia/Manila'));
    expect($flag('2026-11-16'))->toBe(1);
    $this->postJson("{$this->t}/move-out", ['moved_out_on' => '2026-11-16'])->assertOk()->assertJsonPath('data.unit_status', 'available');
    expect($this->tenancy->fresh()->end_on->toDateString())->toBe('2026-11-14'); // kept: the overstay stays on record
});

it('skips the final bill when a later bill already carried the utilities', function () {
    Date::setTestNow(CarbonImmutable::parse('2026-11-25 10:00', 'Asia/Manila'));
    app(BillingService::class)->issueDuePeriodicBills(CarbonImmutable::parse('2026-11-25', 'Asia/Manila')); // carries water Oct 26 – Nov 25
    Date::setTestNow(CarbonImmutable::parse('2026-11-28 10:00', 'Asia/Manila'));

    $this->actingAs($this->owner, 'sanctum')->postJson("{$this->t}/move-out", ['moved_out_on' => '2026-11-20', 'reason' => 'Left early'])->assertOk()
        ->assertJsonPath('data.final_bill', null);
});

it('closes a whole room: everyone listed leaves and the leader steps down; metered bills count days until the move-out', function () {
    Date::setTestNow(CarbonImmutable::parse('2026-09-01 10:00', 'Asia/Manila'));
    $room = app(PropertySetup::class)->createRoom($this->property, RentalMode::Whole, ['capacity' => 3, 'rent_centavos' => 450000], $this->owner, ['door_no' => 'B']);
    $leo = User::factory()->boarder()->create(['name' => 'Leo']);
    $stay = Tenancy::factory()->forUnit($room->units()->first())->movedInOn('2026-09-01')->create(['tenant_id' => $leo->id]);
    app(UnitStatusService::class)->move($room->units()->first(), UnitStatus::Occupied);
    RoomOccupant::factory()->create(['room_id' => $room->id, 'tenancy_id' => $stay->id, 'user_id' => $leo->id, 'name' => 'Leo', 'joined_on' => '2026-09-01']);
    RoomOccupant::factory()->create(['room_id' => $room->id, 'tenancy_id' => $stay->id, 'name' => 'Ericka', 'joined_on' => '2026-09-10']);
    RoomLeader::factory()->create(['room_id' => $room->id, 'user_id' => $leo->id]);

    Date::setTestNow(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Manila'));
    $this->actingAs($this->owner, 'sanctum')->putJson("/api/v1/tenancies/{$stay->id}/end-date", ['end_on' => '2026-10-04', 'reason' => 'Lease ends'])->assertOk();
    $this->postJson("/api/v1/tenancies/{$stay->id}/move-out", ['moved_out_on' => '2026-10-04'])->assertOk();

    expect(RoomOccupant::whereNull('left_on')->where('room_id', $room->id)->count())->toBe(0)
        ->and(RoomLeader::current()->where('room_id', $room->id)->exists())->toBeFalse()
        ->and($stay->fresh()->status)->toBe(TenancyStatus::Ended);

    // Meralco Sep 10 – Oct 9: Leo Sep 10 – Oct 4 (25 days) + Ericka Sep 10 – Oct 4 (25 days).
    $meralco = $this->property->utilityAccounts()->create(['room_id' => $room->id, 'type' => 'electricity', 'name' => 'Meralco', 'method' => 'actual_bill', 'billed_by' => 'owner']);
    expect(app(MeteredBillService::class)->occupantDays($meralco, '2026-09-10', '2026-10-09'))->toBe([$room->id => 50]);
});
