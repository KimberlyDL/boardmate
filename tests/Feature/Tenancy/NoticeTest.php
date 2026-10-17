<?php

use App\Enums\BillKind;
use App\Enums\CaretakerAccessLevel;
use App\Enums\NoticeStatus;
use App\Enums\NotificationEvent;
use App\Enums\TenancyStatus;
use App\Enums\UnitStatus;
use App\Models\Bill;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Notifications\DormLinkNotification;
use App\Services\Properties\UnitStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    // The property (and its rent) exists from Sep 1; the tests run on Nov 1.
    Date::setTestNow(CarbonImmutable::parse('2026-09-01 10:00', 'Asia/Manila'));
    $this->owner = User::factory()->owner()->create();
    $this->property = makeProperty($this->owner);
    Date::setTestNow(CarbonImmutable::parse('2026-11-01 10:00', 'Asia/Manila'));
    $this->property->settings->update(['minimum_notice_days' => 14]);
    $this->unit = $this->property->units()->first();
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $this->tenancy = Tenancy::factory()->forUnit($this->unit)->movedInOn('2026-09-25')->create(['tenant_id' => $this->kim->id]);
    app(UnitStatusService::class)->move($this->unit, UnitStatus::Occupied);
    $this->collector = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Collector)->create();
    assignCaretaker($this->property, $this->collector, 'collector');
    $this->t = "/api/v1/tenancies/{$this->tenancy->id}";
});

afterEach(fn () => Date::setTestNow());

it('takes a boarder\'s notice, flags it short, and makes the tenancy Leaving once approved (SC-10)', function () {
    $this->actingAs($this->kim, 'sanctum')->postJson("{$this->t}/notice", ['end_on' => '2026-10-31'])
        ->assertUnprocessable()->assertJsonValidationErrors(['end_on']); // not in the past
    $this->postJson("{$this->t}/notice", ['end_on' => '2026-11-10'])->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.is_short_notice', true); // 9 days < 14
    $this->postJson("{$this->t}/notice", ['end_on' => '2026-11-25'])->assertUnprocessable();

    Notification::assertSentTo([$this->owner, $this->collector], DormLinkNotification::class,
        fn ($n) => $n->event === NotificationEvent::NoticeFiled && $n->data['is_short_notice'] === true && $n->data['end_on'] === 'Nov 10, 2026');
    $this->getJson($this->t)->assertOk()->assertJsonPath('data.pending_notice.end_on', '2026-11-10')->assertJsonPath('data.status', 'active');

    $this->actingAs($this->collector, 'sanctum')->getJson("/api/v1/properties/{$this->property->id}/notices")->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.tenancy.tenant.name', 'Kim');
    $this->postJson("{$this->t}/notice/approve")->assertOk()
        ->assertJsonPath('data.status', 'notice_given')
        ->assertJsonPath('data.status_label', 'Leaving')
        ->assertJsonPath('data.end_on', '2026-11-10')
        ->assertJsonPath('data.unit_status', 'leaving');
    $this->postJson("{$this->t}/notice/approve")->assertUnprocessable();

    Notification::assertSentTo($this->kim, DormLinkNotification::class,
        fn ($n) => $n->event === NotificationEvent::EndDateChanged && $n->data['change'] === 'approved');
    $this->actingAs($this->kim, 'sanctum')->postJson("{$this->t}/notice", ['end_on' => '2026-11-20'])->assertUnprocessable();
});

it('lets the boarder withdraw a waiting notice, and staff decline one with a reason', function () {
    $this->actingAs($this->kim, 'sanctum')->postJson("{$this->t}/notice", ['end_on' => '2026-11-30'])->assertCreated();
    $this->deleteJson("{$this->t}/notice")->assertOk();
    $this->deleteJson("{$this->t}/notice")->assertUnprocessable();

    $this->postJson("{$this->t}/notice", ['end_on' => '2026-11-30'])->assertCreated()->assertJsonPath('data.is_short_notice', false);
    $this->actingAs($this->owner, 'sanctum')->postJson("{$this->t}/notice/decline")->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $this->postJson("{$this->t}/notice/decline", ['reason' => 'Talk to me first'])->assertOk();

    expect($this->tenancy->fresh()->status)->toBe(TenancyStatus::Active)
        ->and($this->unit->fresh()->status)->toBe(UnitStatus::Occupied);
    $this->actingAs($this->kim, 'sanctum')->getJson("{$this->t}/notices")->assertOk()
        ->assertJsonPath('data.0.status', 'declined')
        ->assertJsonPath('data.0.decline_reason', 'Talk to me first')
        ->assertJsonPath('data.1.status', 'withdrawn');
    Notification::assertSentTo($this->kim, DormLinkNotification::class,
        fn ($n) => $n->event === NotificationEvent::EndDateChanged && $n->data['change'] === 'declined' && $n->data['reason'] === 'Talk to me first');
});

it('lets staff set an end date for someone who left without notice, change it and cancel it (SC-11, SC-29)', function () {
    $this->actingAs($this->kim, 'sanctum')->postJson("{$this->t}/notice", ['end_on' => '2026-11-30'])->assertCreated();
    $this->actingAs($this->collector, 'sanctum');

    $this->putJson("{$this->t}/end-date", ['end_on' => '2026-10-28'])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $this->putJson("{$this->t}/end-date", ['end_on' => '2026-09-01', 'reason' => 'X'])->assertUnprocessable(); // before 30 days back
    $this->putJson("{$this->t}/end-date", ['end_on' => '2026-10-28', 'reason' => 'Left without notice; room empty since Oct 28'])->assertOk()
        ->assertJsonPath('data.end_on', '2026-10-28')
        ->assertJsonPath('data.unit_status', 'leaving');
    $this->getJson("{$this->t}/notices")->assertJsonPath('data.0.status', 'approved')->assertJsonPath('data.1.status', 'replaced');

    // Stayed on after the end date: the end date is extended (SC-29).
    $this->putJson("{$this->t}/end-date", ['end_on' => '2026-11-15', 'reason' => 'Agreed to stay two more weeks'])->assertOk()->assertJsonPath('data.end_on', '2026-11-15');
    $this->putJson("{$this->t}/end-date", ['end_on' => '2026-11-15', 'reason' => 'Same'])->assertUnprocessable();

    $this->deleteJson("{$this->t}/end-date", ['reason' => 'Decided to stay'])->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.end_on', null)
        ->assertJsonPath('data.unit_status', 'occupied');
    Notification::assertSentTo($this->kim, DormLinkNotification::class, fn ($n) => $n->event === NotificationEvent::EndDateChanged && $n->data['change'] === 'cancelled');

    $this->actingAs(User::factory()->owner()->create(), 'sanctum')->putJson("{$this->t}/end-date", ['end_on' => '2026-11-15', 'reason' => 'X'])->assertNotFound();
    $this->actingAs(User::factory()->boarder()->create(), 'sanctum')->postJson("{$this->t}/notice", ['end_on' => '2026-11-15'])->assertNotFound();
});

it('keeps billing a Leaving tenancy, but starts no rent period after the last day', function () {
    $this->actingAs($this->owner, 'sanctum')->putJson("{$this->t}/end-date", ['end_on' => '2026-11-25', 'reason' => 'Graduating'])->assertOk();

    // Oct 25 bill (period Oct 26 – Nov 25) was due before the end; Nov 25 would start Nov 26: not billed.
    $issue = fn (string $day) => app(BillingService::class)->issueDuePeriodicBills(CarbonImmutable::parse($day, 'Asia/Manila'));
    expect($issue('2026-12-31')['issued'])->toBe(1)
        ->and(Bill::where('kind', BillKind::Periodic)->pluck('period_start_on')->map->toDateString()->all())->toBe(['2026-10-26']);
    expect($this->tenancy->notices()->first()->status)->toBe(NoticeStatus::Approved);
});

it('shows the boarder the notice rules on their stay, and their past stays separately', function () {
    $this->property->settings->update(['suggested_notice_days' => 30]);
    $this->actingAs($this->kim, 'sanctum')->getJson('/api/v1/me/tenancies')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.notice_rules.suggested_days', 30)
        ->assertJsonPath('data.0.notice_rules.minimum_days', 14)
        ->assertJsonPath('data.0.is_settled', false);
    $this->getJson('/api/v1/me/tenancies?past=1')->assertOk()->assertJsonCount(0, 'data');

    $this->tenancy->update(['status' => TenancyStatus::Settled, 'end_on' => '2026-10-31', 'moved_out_on' => '2026-10-31']);
    $this->getJson('/api/v1/me/tenancies')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/me/tenancies?past=1')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.moved_out_on', '2026-10-31')
        ->assertJsonPath('data.0.is_settled', true);
});
