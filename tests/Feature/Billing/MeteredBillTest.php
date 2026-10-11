<?php

use App\Enums\BillKind;
use App\Enums\BillLineKind;
use App\Enums\CaretakerAccessLevel;
use App\Enums\NotificationEvent;
use App\Enums\RentalMode;
use App\Models\Bill;
use App\Models\RoomLeader;
use App\Models\RoomOccupant;
use App\Models\Tenancy;
use App\Models\User;
use App\Models\UtilityBill;
use App\Services\Notifications\DormLinkNotification;
use App\Services\Properties\PropertySetup;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Notification::fake();
    Storage::fake('local');
    Date::setTestNow(CarbonImmutable::parse('2026-10-12 10:00', 'Asia/Manila'));

    $this->owner = User::factory()->owner()->create();
    // Room A: two bedspaces. Room B: rented whole.
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
    $leoStay = Tenancy::factory()->forUnit($this->roomB->units()->first())->movedInOn('2026-09-01')->create(['tenant_id' => $this->leo->id]);
    RoomOccupant::factory()->create(['room_id' => $this->roomB->id, 'tenancy_id' => $leoStay->id, 'user_id' => $this->leo->id, 'name' => 'Leo', 'joined_on' => '2026-09-01']);
    RoomOccupant::factory()->create(['room_id' => $this->roomB->id, 'tenancy_id' => $leoStay->id, 'name' => 'Ericka', 'joined_on' => '2026-09-10']);
    RoomLeader::factory()->create(['room_id' => $this->roomA->id, 'user_id' => $this->kim->id]);
    RoomLeader::factory()->create(['room_id' => $this->roomB->id, 'user_id' => $this->leo->id]);

    $this->meralco = $this->property->utilityAccounts()->create(['type' => 'electricity', 'name' => 'Meralco', 'method' => 'actual_bill', 'billed_by' => 'owner']);
});

afterEach(fn () => Date::setTestNow());

function enterMeralco(array $extra = [])
{
    return test()->actingAs(test()->owner, 'sanctum')->post('/api/v1/properties/'.test()->property->id.'/utility-bills', $extra + [
        'utility_account_id' => test()->meralco->id,
        'coverage_from_on' => '2026-09-10',
        'coverage_to_on' => '2026-10-09',
        'total_centavos' => 180000,
    ], ['Accept' => 'application/json']);
}

it('splits a whole-property meter by occupant-days and bills each room\'s leader, due in 7 days (SC-20)', function () {
    // Room A: Kim 30 days + Ben 15 (from Sep 25) = 45. Room B: Leo 30 + Ericka 30 = 60.
    $preview = $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/properties/{$this->property->id}/utility-bills/preview", [
        'utility_account_id' => $this->meralco->id, 'coverage_from_on' => '2026-09-10', 'coverage_to_on' => '2026-10-09', 'total_centavos' => 180000,
    ])->assertOk()->json('data');
    expect(collect($preview)->map(fn ($s) => [$s['room_label'], $s['occupant_days'], $s['amount_centavos'], $s['leader']['name']])->all())
        ->toBe([['A', 45, 77143, 'Kim'], ['B', 60, 102857, 'Leo']]);

    enterMeralco(['photo' => UploadedFile::fake()->image('bill.jpg')])->assertCreated()->assertJsonPath('data.total_centavos', 180000);

    $bills = Bill::with('lines')->orderBy('room_id')->get();
    expect($bills)->toHaveCount(2)
        ->and($bills->pluck('payer_id')->all())->toBe([$this->kim->id, $this->leo->id])
        ->and($bills[0]->kind)->toBe(BillKind::Utility)
        ->and($bills[0]->due_on->toDateString())->toBe('2026-10-19')
        ->and($bills[0]->lines->sole()->kind)->toBe(BillLineKind::UtilityShare)
        ->and($bills[0]->lines->sole()->description)->toBe("Meralco, Sep 10 – Oct 9, 2026: room A's share (45 occupant-days)")
        ->and($bills->sum(fn ($b) => $b->totalCentavos()))->toBe(180000)
        ->and(UtilityBill::sole()->photo_path)->not->toBeNull();

    Notification::assertSentTo($this->kim, DormLinkNotification::class,
        fn ($n) => $n->event === NotificationEvent::BillIssued && $n->data['total'] === '₱771.43' && $n->data['due_on'] === 'Oct 19, 2026');
});

it('bills a room\'s own meter to that room only', function () {
    $roomMeter = $this->property->utilityAccounts()->create(['room_id' => $this->roomB->id, 'type' => 'electricity', 'name' => 'Room B meter', 'method' => 'actual_bill', 'billed_by' => 'owner']);

    enterMeralco(['utility_account_id' => $roomMeter->id, 'total_centavos' => 90000])->assertCreated();

    expect(Bill::sole()->payer_id)->toBe($this->leo->id)
        ->and(Bill::sole()->totalCentavos())->toBe(90000)
        ->and(Bill::sole()->lines()->value('description'))->toBe("Room B meter, Sep 10 – Oct 9, 2026: room B's share");
});

it('stops when a room with a share has no leader, until it gets one or its share is waived', function () {
    RoomLeader::where('room_id', $this->roomA->id)->delete();

    enterMeralco()->assertUnprocessable()->assertJsonPath('errors.waive_room_ids.0', 'Room A has no leader. Appoint one, or waive its share.');
    expect(Bill::count())->toBe(0);

    enterMeralco(['waive_room_ids' => [$this->roomA->id]])->assertCreated();
    expect(Bill::sole()->payer_id)->toBe($this->leo->id)
        ->and(Bill::sole()->totalCentavos())->toBe(102857)
        ->and(collect(UtilityBill::sole()->shares)->firstWhere('room_id', $this->roomA->id)['waived'])->toBeTrue();
});

it('corrects a wrong total with the same split and an adjustment line on each bill (SC-14)', function () {
    // Two rooms with equal occupant-days: ₱1,800 is ₱900 each; corrected to ₱1,080 → ₱540 each, ₱360 credit each.
    RoomOccupant::where('name', 'Ericka')->delete();
    Tenancy::where('tenant_id', $this->ben->id)->delete();
    $id = enterMeralco()->json('data.id');
    expect(Bill::orderBy('id')->get()->map->totalCentavos()->all())->toBe([90000, 90000]);

    $this->postJson("/api/v1/utility-bills/{$id}/correct", ['total_centavos' => 108000, 'reason' => 'Typed the wrong amount'])
        ->assertOk()->assertJsonPath('data.replaces_id', $id)->assertJsonPath('data.total_centavos', 108000);

    $bills = Bill::with('lines')->orderBy('id')->get();
    expect($bills->map->totalCentavos()->all())->toBe([54000, 54000])
        ->and($bills[0]->lines->last()->kind)->toBe(BillLineKind::Adjustment)
        ->and($bills[0]->lines->last()->amount_centavos)->toBe(-36000)
        ->and($bills[0]->lines->last()->reason)->toBe('Typed the wrong amount');
    Notification::assertSentTo($this->kim, DormLinkNotification::class, fn ($n) => $n->event === NotificationEvent::BillAdjusted && $n->data['change'] === '-₱360.00');

    // Only the latest entry can be corrected, and the list shows only it.
    $this->postJson("/api/v1/utility-bills/{$id}/correct", ['total_centavos' => 100000, 'reason' => 'Again'])->assertUnprocessable();
    $this->getJson("/api/v1/properties/{$this->property->id}/utility-bills")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.total_centavos', 108000);
});

it('refuses fixed-price or boarder-paid utilities, and lets Managers but not Collectors enter bills', function () {
    $fixed = $this->property->utilityAccounts()->create(['type' => 'water', 'name' => 'Water', 'method' => 'fixed_per_bedspace', 'billed_by' => 'owner']);
    enterMeralco(['utility_account_id' => $fixed->id])->assertUnprocessable()->assertJsonValidationErrors(['utility_account_id']);

    $manager = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Manager)->create();
    $collector = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Collector)->create();
    assignCaretaker($this->property, $manager, 'manager');
    assignCaretaker($this->property, $collector, 'collector');

    $this->actingAs($collector, 'sanctum')->postJson("/api/v1/properties/{$this->property->id}/utility-bills", [
        'utility_account_id' => $this->meralco->id, 'coverage_from_on' => '2026-09-10', 'coverage_to_on' => '2026-10-09', 'total_centavos' => 180000,
    ])->assertForbidden();
    $this->actingAs($manager, 'sanctum')->postJson("/api/v1/properties/{$this->property->id}/utility-bills", [
        'utility_account_id' => $this->meralco->id, 'coverage_from_on' => '2026-09-10', 'coverage_to_on' => '2026-10-09', 'total_centavos' => 180000,
    ])->assertCreated();
    $this->actingAs($collector, 'sanctum')->getJson("/api/v1/properties/{$this->property->id}/utility-bills")->assertOk()->assertJsonCount(1, 'data');
});

it('lets a utility account cover one room of the property', function () {
    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/properties/{$this->property->id}/utility-accounts", [
        'type' => 'electricity', 'method' => 'actual_bill', 'room_id' => $this->roomB->id,
    ])->assertCreated()->assertJsonPath('data.room.label', 'B');

    $other = makeProperty($this->owner)->rooms()->first();
    $this->postJson("/api/v1/properties/{$this->property->id}/utility-accounts", [
        'type' => 'electricity', 'method' => 'actual_bill', 'room_id' => $other->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['room_id']);
});
