<?php

use App\Enums\BillKind;
use App\Enums\CaretakerAccessLevel;
use App\Enums\MaintenanceStatus;
use App\Models\Bill;
use App\Models\MaintenanceRequest;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\Scheduler\Jobs\CloseResolvedMaintenanceJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Manila'));
    Notification::fake();

    $this->owner = User::factory()->owner()->create(['name' => 'Ana Santos']);
    $this->manager = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Manager)->create(['name' => 'Mario']);
    $this->collector = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Collector)->create(['name' => 'Carla']);
    $this->property = makeProperty($this->owner, ['units' => ['count' => 2, 'rent_centavos' => 200000]]);
    assignCaretaker($this->property, $this->manager, 'manager');
    assignCaretaker($this->property, $this->collector, 'collector');
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $this->unit = $this->property->units()->first();
    $this->stay = Tenancy::factory()->forUnit($this->unit)->movedInOn('2026-10-01')->create(['tenant_id' => $this->kim->id]);
    $this->request = MaintenanceRequest::factory()->forTenancy($this->stay)->create();
    $this->url = "/api/v1/maintenance-requests/{$this->request->id}";
});

afterEach(fn () => Date::setTestNow());

it('lets the boarder confirm the fix, or send it back as still broken', function () {
    $this->actingAs($this->collector, 'sanctum')->postJson("{$this->url}/resolve")->assertOk();
    $this->postJson("{$this->url}/confirm")->assertForbidden(); // only the one who reported it

    $this->actingAs($this->kim, 'sanctum')->getJson($this->url)->assertJsonPath('data.can.confirm', true)->assertJsonPath('data.can.reopen', true);
    $this->postJson("{$this->url}/reopen")->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $this->postJson("{$this->url}/reopen", ['reason' => 'Still dripping at night.'])->assertOk()
        ->assertJsonPath('data.status', 'in_progress')
        ->assertJsonPath('data.resolved_at', null);
    $this->postJson("{$this->url}/confirm")->assertUnprocessable()->assertJsonValidationErrors(['status']);

    $this->actingAs($this->collector, 'sanctum')->postJson("{$this->url}/resolve")->assertOk();
    $history = $this->actingAs($this->kim, 'sanctum')->postJson("{$this->url}/confirm")->assertOk()
        ->assertJsonPath('data.status', 'closed')->json('data.history');
    expect(array_column($history, 'body'))->toContain('Still broken: Still dripping at night.', 'Confirmed it is fixed.');
});

it('closes a fixed request by itself 3 days later', function () {
    $this->actingAs($this->collector, 'sanctum')->postJson("{$this->url}/resolve")->assertOk(); // Oct 18
    $job = app(CloseResolvedMaintenanceJob::class);

    $job->run(CarbonImmutable::parse('2026-10-20', 'Asia/Manila'));
    expect($this->request->fresh()->status)->toBe(MaintenanceStatus::Resolved);

    expect($job->run(CarbonImmutable::parse('2026-10-21', 'Asia/Manila')))->toBe('Closed 1 fixed request(s).')
        ->and($this->request->fresh()->status)->toBe(MaintenanceStatus::Closed)
        ->and($this->request->fresh()->notes()->reorder('id', 'desc')->value('body'))->toBe('Closed: nobody said it was still broken within 3 days.');
    expect(config('dormlink.daily_jobs'))->toContain(CloseResolvedMaintenanceJob::class);
});

it('lets the owner or a Manager charge the boarder once for a repair they caused', function () {
    $this->actingAs($this->collector, 'sanctum')->postJson("{$this->url}/charge", ['amount_centavos' => 50000, 'reason' => 'Broke the faucet'])->assertForbidden();

    $this->actingAs($this->manager, 'sanctum')->postJson("{$this->url}/charge", ['amount_centavos' => 50000])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $this->postJson("{$this->url}/charge", ['amount_centavos' => 50000, 'reason' => 'Broke the faucet'])->assertOk()
        ->assertJsonPath('data.charge.amount_centavos', 50000)
        ->assertJsonPath('data.can.charge', false);
    $this->postJson("{$this->url}/charge", ['amount_centavos' => 100, 'reason' => 'Again'])->assertUnprocessable()->assertJsonValidationErrors(['amount_centavos']);

    $bill = Bill::sole();
    expect($bill->kind)->toBe(BillKind::Charge)
        ->and($bill->payer_id)->toBe($this->kim->id)
        ->and($bill->lines()->value('description'))->toBe('Repair: Plumbing')
        ->and($bill->lines()->value('reason'))->toBe('Broke the faucet');

    // A request staff logged for the whole place has nobody to charge.
    $logged = MaintenanceRequest::factory()->create(['property_id' => $this->property->id, 'reported_by' => $this->owner->id]);
    $this->postJson("/api/v1/maintenance-requests/{$logged->id}/charge", ['amount_centavos' => 100, 'reason' => 'x'])->assertUnprocessable();
});

it('sets the stay\'s unit Not ready during the repair and ready again after', function () {
    $this->actingAs($this->collector, 'sanctum')->putJson("{$this->url}/not-ready", ['not_ready' => true])->assertForbidden();

    $this->actingAs($this->manager, 'sanctum')->putJson("{$this->url}/not-ready", ['not_ready' => true])->assertOk()
        ->assertJsonPath('data.unit.id', $this->unit->id)
        ->assertJsonPath('data.unit.not_ready', true)
        ->assertJsonPath('data.unit.not_ready_reason', 'Repair: Plumbing');
    expect($this->unit->fresh()->not_ready)->toBeTrue();

    $this->putJson("{$this->url}/not-ready", ['not_ready' => false])->assertOk()->assertJsonPath('data.unit.not_ready', false);
    expect($this->unit->fresh()->not_ready_reason)->toBeNull();

    // Another room's unit cannot be marked from this request.
    $other = $this->property->units()->where('room_id', '!=', $this->stay->room_id)->first();
    if ($other) {
        $this->putJson("{$this->url}/not-ready", ['not_ready' => true, 'unit_id' => $other->id])->assertUnprocessable()->assertJsonValidationErrors(['unit_id']);
    }
    $this->getJson('/api/v1/owner/audit-log?event=unit.not_ready_changed')->assertForbidden(); // the owner's log, not a Manager's
    $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/owner/audit-log?event=unit.not_ready_changed')->assertJsonCount(2, 'data');
});
