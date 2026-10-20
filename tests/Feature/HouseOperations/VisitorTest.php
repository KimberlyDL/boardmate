<?php

use App\Enums\CaretakerAccessLevel;
use App\Enums\VisitorStatus;
use App\Models\Tenancy;
use App\Models\User;
use App\Models\Visitor;
use App\Services\Scheduler\Jobs\PurgeOldVisitorsJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Manila'));
    $this->owner = User::factory()->owner()->create();
    $this->collector = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Collector)->create(['name' => 'Carla']);
    $this->property = makeProperty($this->owner);
    assignCaretaker($this->property, $this->collector, 'collector');
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $this->stay = Tenancy::factory()->forUnit($this->property->units()->first())->movedInOn('2026-10-01')->create(['tenant_id' => $this->kim->id]);
    $this->url = "/api/v1/tenancies/{$this->stay->id}/visitors";
});

afterEach(fn () => Date::setTestNow());

function registerVisit(array $extra = [])
{
    return test()->actingAs(test()->kim, 'sanctum')->postJson(test()->url, $extra + ['name' => 'Ana Cruz', 'visit_on' => '2026-10-20', 'expected_in' => '14:00', 'expected_out' => '18:00']);
}

it('registers a daytime visit with no approval, and an overnight stay that waits for approval', function () {
    registerVisit()->assertCreated()->assertJsonPath('data.status', 'registered')->assertJsonPath('message', 'Visitor registered.');
    registerVisit(['overnight' => true, 'nights' => 2])->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.status_label', 'Waiting for approval')
        ->assertJsonPath('message', 'Registered. The overnight stay waits for approval.');

    registerVisit(['visit_on' => '2026-10-17'])->assertUnprocessable()->assertJsonValidationErrors(['visit_on']);
    registerVisit(['visit_on' => '2026-11-18'])->assertUnprocessable()->assertJsonValidationErrors(['visit_on']);
    registerVisit(['overnight' => true, 'nights' => 8])->assertUnprocessable()->assertJsonValidationErrors(['nights']);

    // Without the approval setting, overnight stays are just registered.
    $this->property->settings()->updateOrCreate([], ['overnight_requires_approval' => false]);
    registerVisit(['overnight' => true])->assertCreated()->assertJsonPath('data.status', 'registered');
    $this->getJson($this->url)->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.nights', ['used' => 1, 'limit' => 3, 'needs_approval' => false]);
});

it('flags overnight nights over the monthly limit without blocking them', function () {
    Visitor::factory()->forTenancy($this->stay)->overnight(2)->create(['visit_on' => '2026-10-05']);
    Visitor::factory()->forTenancy($this->stay)->overnight(3, VisitorStatus::Declined)->create(['visit_on' => '2026-10-06']); // does not count

    $id = registerVisit(['overnight' => true, 'nights' => 2])->assertCreated()->assertJsonPath('data.over_limit', true)->json('data.id');
    registerVisit(['overnight' => true, 'visit_on' => '2026-11-02'])->assertCreated()->assertJsonPath('data.over_limit', false); // a new month

    $this->actingAs($this->collector, 'sanctum')->postJson("/api/v1/visitors/{$id}/approve")->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.over_limit', true)
        ->assertJsonPath('message', 'Approved. It goes over the monthly limit.');
});

it('lets any caretaker decide, check in and out, and log a walk-in', function () {
    $overnight = registerVisit(['overnight' => true])->json('data.id');
    $daytime = registerVisit(['visit_on' => '2026-10-18'])->json('data.id');
    $base = "/api/v1/properties/{$this->property->id}/visitors";
    $this->actingAs($this->collector, 'sanctum');

    $this->postJson("/api/v1/visitors/{$overnight}/check-in")->assertUnprocessable()->assertJsonPath('errors.status.0', 'This overnight stay is not approved yet.');
    $this->getJson("{$base}?view=pending")->assertJsonCount(1, 'data')->assertJsonPath('data.0.can.decide', true);
    $this->postJson("/api/v1/visitors/{$overnight}/decline")->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $this->postJson("/api/v1/visitors/{$overnight}/decline", ['reason' => 'Exam week: no overnight guests.'])->assertOk()->assertJsonPath('data.decided_by', 'Carla');

    $this->postJson("/api/v1/visitors/{$daytime}/check-in")->assertOk()->assertJsonPath('data.status', 'checked_in');
    $walkIn = $this->postJson($base, ['tenancy_id' => $this->stay->id, 'name' => 'Leo'])->assertCreated()
        ->assertJsonPath('data.status', 'checked_in')->assertJsonPath('data.is_walk_in', true)->json('data.id');
    $this->postJson($base, ['tenancy_id' => 999999, 'name' => 'X'])->assertUnprocessable()->assertJsonValidationErrors(['tenancy_id']);

    expect(collect($this->getJson($base)->json('data'))->pluck('id')->sort()->values()->all())->toBe([$daytime, $walkIn]);
    $this->postJson("/api/v1/visitors/{$walkIn}/check-out")->assertOk()->assertJsonPath('data.status', 'checked_out');
    $this->postJson("/api/v1/visitors/{$walkIn}/check-out")->assertUnprocessable();

    // The tenant cannot act as staff; strangers see nothing.
    $this->actingAs($this->kim, 'sanctum')->postJson("/api/v1/visitors/{$daytime}/check-out")->assertNotFound();
    $this->actingAs(User::factory()->owner()->create(), 'sanctum')->getJson($base)->assertNotFound();
    $this->getJson($this->url)->assertNotFound();
});

it('lets the tenant cancel before the visit, and deletes records 90 days after', function () {
    $id = registerVisit()->json('data.id');
    $this->postJson("/api/v1/visitors/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson("/api/v1/visitors/{$id}/cancel")->assertUnprocessable();

    Visitor::factory()->forTenancy($this->stay)->create(['visit_on' => '2026-07-19', 'status' => VisitorStatus::CheckedOut]); // 91 days ago
    Visitor::factory()->forTenancy($this->stay)->create(['visit_on' => '2026-07-20', 'status' => VisitorStatus::CheckedOut]); // 90 days: kept
    expect(app(PurgeOldVisitorsJob::class)->run(CarbonImmutable::parse('2026-10-18', 'Asia/Manila')))->toBe('Deleted 1 old visitor record(s).')
        ->and(Visitor::count())->toBe(2)
        ->and(config('dormlink.daily_jobs'))->toContain(PurgeOldVisitorsJob::class);
});
