<?php

use App\Enums\CaretakerAccessLevel;
use App\Enums\NotificationEvent;
use App\Models\MaintenanceRequest;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\Notifications\DormLinkNotification;
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
    Tenancy::factory()->forUnit($this->property->units()->first())->movedInOn('2026-10-01')->create(['tenant_id' => $this->kim->id]);
});

afterEach(fn () => Date::setTestNow());

/** The DormLinkNotifications $user got for $event (each counted once). */
function maintenanceSent(User $user, NotificationEvent $event)
{
    return Notification::sent($user, DormLinkNotification::class)->filter(fn ($n) => $n->event === $event)->unique(fn ($n) => spl_object_id($n))->values();
}

function reportProblem(string $urgency): int
{
    return test()->actingAs(test()->kim, 'sanctum')->postJson('/api/v1/me/maintenance-requests', [
        'category' => 'plumbing', 'description' => 'The sink is leaking.', 'urgency' => $urgency,
    ])->assertCreated()->json('data.id');
}

it('tells the owner and every caretaker about a new request: urgent by email too, normal in the app only', function () {
    reportProblem('urgent');
    foreach ([$this->owner, $this->manager, $this->collector] as $staff) {
        $sent = maintenanceSent($staff, NotificationEvent::MaintenanceUrgent);
        expect($sent)->toHaveCount(1)
            ->and($sent[0]->via($staff))->toContain('mail')
            ->and($sent[0]->data)->toMatchArray(['reporter' => 'Kim', 'category' => 'Plumbing', 'problem' => 'The sink is leaking.']);
    }
    expect(maintenanceSent($this->kim, NotificationEvent::MaintenanceUrgent))->toHaveCount(0);

    reportProblem('normal');
    $normal = maintenanceSent($this->owner, NotificationEvent::MaintenanceReported);
    expect($normal)->toHaveCount(1)->and($normal[0]->via($this->owner))->toBe(['database']);
});

it('keeps the boarder up to date, and staff hear back from the boarder', function () {
    $id = reportProblem('normal');
    $url = "/api/v1/maintenance-requests/{$id}";

    $this->actingAs($this->collector, 'sanctum')->postJson("{$url}/start")->assertOk();
    $this->putJson("{$url}/assignee", ['user_id' => $this->manager->id])->assertOk();
    $this->postJson("{$url}/resolve", ['resolution' => 'Replaced the pipe.'])->assertOk();

    $updates = maintenanceSent($this->kim, NotificationEvent::MaintenanceUpdated);
    expect($updates->pluck('data.what')->all())->toBe(['started', 'resolved'])
        ->and($updates[1]->data['detail'])->toBe('Replaced the pipe.');
    expect(maintenanceSent($this->manager, NotificationEvent::MaintenanceForStaff)->pluck('data.what')->all())->toBe(['assigned']);

    // "Still broken" and the boarder's notes go to the one assigned only.
    $this->actingAs($this->kim, 'sanctum')->postJson("{$url}/reopen", ['reason' => 'Still dripping.'])->assertOk();
    $this->postJson("{$url}/notes", ['body' => 'I am home after 5 PM.'])->assertOk();
    expect(maintenanceSent($this->manager, NotificationEvent::MaintenanceForStaff)->pluck('data.what')->all())->toBe(['assigned', 'still_broken', 'note'])
        ->and(maintenanceSent($this->owner, NotificationEvent::MaintenanceForStaff))->toHaveCount(0);

    // Nobody is told about their own action.
    expect(maintenanceSent($this->collector, NotificationEvent::MaintenanceForStaff))->toHaveCount(0);
});

it('tells the boarder when staff cancel, and staff when the boarder withdraws', function () {
    $first = reportProblem('normal');
    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/maintenance-requests/{$first}/cancel", ['reason' => 'Not a repair'])->assertOk();
    expect(maintenanceSent($this->kim, NotificationEvent::MaintenanceUpdated)->first()->data)->toMatchArray(['what' => 'cancelled', 'detail' => 'Not a repair', 'by' => 'Ana Santos']);

    $second = reportProblem('normal');
    $this->actingAs($this->kim, 'sanctum')->postJson("/api/v1/maintenance-requests/{$second}/cancel")->assertOk();
    expect(maintenanceSent($this->owner, NotificationEvent::MaintenanceForStaff)->pluck('data.what')->all())->toBe(['withdrawn']);
    expect(MaintenanceRequest::count())->toBe(2);
});
