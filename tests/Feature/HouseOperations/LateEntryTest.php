<?php

use App\Enums\CaretakerAccessLevel;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\HouseOperations\LateEntryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Manila'));
    $this->owner = User::factory()->owner()->create();
    $this->collector = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Collector)->create(['name' => 'Carla']);
    $this->property = makeProperty($this->owner);
    assignCaretaker($this->property, $this->collector, 'collector');
    $this->property->settings()->updateOrCreate([], ['curfew_time' => '22:00']);
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $this->stay = Tenancy::factory()->forUnit($this->property->units()->first())->movedInOn('2026-10-01')->create(['tenant_id' => $this->kim->id]);
    $this->url = "/api/v1/tenancies/{$this->stay->id}/late-entries";
});

afterEach(fn () => Date::setTestNow());

function askLate(array $extra = [])
{
    return test()->actingAs(test()->kim, 'sanctum')->postJson(test()->url, $extra + ['on_date' => '2026-10-20', 'expected_arrival' => '23:30', 'reason' => 'Group project at school.']);
}

it('lets a tenant ask ahead, within 7 days, once per day', function () {
    askLate()->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.status_label', 'Waiting')
        ->assertJsonPath('data.can.cancel', true)
        ->assertJsonPath('data.can.decide', false);
    askLate()->assertUnprocessable()->assertJsonPath('errors.on_date.0', 'You already asked for that day. Cancel it first to ask again.');
    askLate(['on_date' => '2026-10-17'])->assertUnprocessable()->assertJsonValidationErrors(['on_date']);
    askLate(['on_date' => '2026-10-26'])->assertUnprocessable()->assertJsonValidationErrors(['on_date']);
    askLate(['on_date' => '2026-10-25'])->assertCreated();
    askLate(['on_date' => '2026-10-21', 'expected_arrival' => '11 PM'])->assertUnprocessable()->assertJsonValidationErrors(['expected_arrival']);

    $this->getJson($this->url)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.on_date', '2026-10-25');
});

it('lets any caretaker approve or decline, and only once', function () {
    $first = askLate()->json('data.id');
    $second = askLate(['on_date' => '2026-10-21'])->json('data.id');
    $base = "/api/v1/properties/{$this->property->id}/late-entries";

    $this->actingAs($this->collector, 'sanctum')->getJson($base)->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.tenant.name', 'Kim')
        ->assertJsonPath('data.0.can.decide', true);
    $this->postJson("/api/v1/late-entries/{$first}/approve")->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.decided_by', 'Carla');
    $this->postJson("/api/v1/late-entries/{$first}/decline", ['reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->postJson("/api/v1/late-entries/{$second}/decline")->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $this->postJson("/api/v1/late-entries/{$second}/decline", ['reason' => 'Exam week: no late entry.'])->assertOk()->assertJsonPath('data.decline_reason', 'Exam week: no late entry.');

    $this->getJson("{$base}?status=upcoming")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first);
    expect(app(LateEntryService::class)->isApproved($this->stay, CarbonImmutable::parse('2026-10-20')))->toBeTrue()
        ->and(app(LateEntryService::class)->isApproved($this->stay, CarbonImmutable::parse('2026-10-21')))->toBeFalse();

    // The tenant cannot decide; strangers see nothing.
    $this->actingAs($this->kim, 'sanctum')->postJson("/api/v1/late-entries/{$second}/approve")->assertNotFound();
    $this->actingAs(User::factory()->owner()->create(), 'sanctum')->getJson($base)->assertNotFound();
    $this->getJson($this->url)->assertNotFound();
});

it('lets the tenant cancel until the day has passed', function () {
    $id = askLate()->json('data.id');
    $this->postJson("/api/v1/late-entries/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    askLate()->assertCreated(); // the day is free again

    $later = askLate(['on_date' => '2026-10-19'])->json('data.id');
    Date::setTestNow(CarbonImmutable::parse('2026-10-20 08:00', 'Asia/Manila'));
    $this->postJson("/api/v1/late-entries/{$later}/cancel")->assertUnprocessable();
    $this->actingAs($this->collector, 'sanctum')->postJson("/api/v1/late-entries/{$later}/cancel")->assertNotFound();
});

it('needs a curfew and a current stay', function () {
    $this->property->settings()->update(['curfew_time' => null]);
    askLate()->assertUnprocessable()->assertJsonPath('errors.on_date.0', 'This place has no curfew, so there is nothing to ask.');

    $this->property->settings()->update(['curfew_time' => '22:00']);
    $this->stay->update(['status' => 'ended', 'moved_out_on' => '2026-10-18']);
    askLate()->assertUnprocessable()->assertJsonValidationErrors(['tenancy']);
});
