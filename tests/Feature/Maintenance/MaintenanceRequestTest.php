<?php

use App\Enums\CaretakerAccessLevel;
use App\Enums\MaintenanceStatus;
use App\Models\MaintenanceRequest;
use App\Models\Tenancy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Manila'));
    Storage::fake('local');
    Storage::fake('public');

    $this->owner = User::factory()->owner()->create(['name' => 'Ana Santos']);
    $this->manager = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Manager)->create(['name' => 'Mario']);
    $this->collector = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Collector)->create(['name' => 'Carla']);
    $this->property = makeProperty($this->owner, ['units' => ['count' => 2, 'rent_centavos' => 200000]]);
    assignCaretaker($this->property, $this->manager, 'manager');
    assignCaretaker($this->property, $this->collector, 'collector');
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $this->stay = Tenancy::factory()->forUnit($this->property->units()->first())->movedInOn('2026-10-01')->create(['tenant_id' => $this->kim->id]);
});

afterEach(fn () => Date::setTestNow());

function reportLeak(array $extra = [])
{
    return test()->actingAs(test()->kim, 'sanctum')->post('/api/v1/me/maintenance-requests', $extra + [
        'category' => 'plumbing', 'description' => 'The sink is leaking.', 'urgency' => 'urgent',
    ], ['Accept' => 'application/json']);
}

it('lets a boarder report a problem in their room, with photos, and see it', function () {
    $id = reportLeak(['photos' => [UploadedFile::fake()->image('leak.jpg', 800, 600), UploadedFile::fake()->image('floor.jpg', 800, 600)]])
        ->assertCreated()
        ->assertJsonPath('data.status', 'submitted')
        ->assertJsonPath('data.urgency_label', 'Urgent')
        ->assertJsonPath('data.room.id', $this->stay->room_id)
        ->assertJsonPath('data.can.cancel', true)
        ->assertJsonMissingPath('data.repair_cost_centavos')
        ->assertJsonCount(2, 'data.photos')
        ->json('data.id');

    $this->getJson('/api/v1/me/maintenance-requests')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.photos_count', 2);
    $this->getJson("/api/v1/maintenance-requests/{$id}")->assertOk()->assertJsonPath('data.photos.0', fn ($url) => str_contains($url, 'maintenance-photos'));

    // Up to 4 photos; images only.
    reportLeak(['photos' => array_fill(0, 5, UploadedFile::fake()->image('x.jpg'))])->assertUnprocessable()->assertJsonValidationErrors(['photos']);
    reportLeak(['photos' => [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')]])->assertUnprocessable()->assertJsonValidationErrors(['photos.0']);

    // Someone without a stay cannot report.
    $this->actingAs(User::factory()->boarder()->create(), 'sanctum')
        ->postJson('/api/v1/me/maintenance-requests', ['category' => 'other', 'description' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['tenancy']);
});

it('takes a request from submitted to in progress to fixed, with a history line for each step', function () {
    $id = reportLeak()->json('data.id');
    $url = "/api/v1/maintenance-requests/{$id}";

    $this->actingAs($this->collector, 'sanctum');
    $this->postJson("{$url}/start")->assertOk()->assertJsonPath('data.status', 'in_progress');
    $this->postJson("{$url}/start")->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->putJson("{$url}/assignee", ['user_id' => $this->manager->id])->assertOk()->assertJsonPath('data.assignee.name', 'Mario');
    $this->putJson("{$url}/assignee", ['user_id' => User::factory()->owner()->create()->id])->assertUnprocessable()->assertJsonValidationErrors(['user_id']);
    $this->postJson("{$url}/notes", ['body' => 'Plumber comes tomorrow.'])->assertOk();
    $this->postJson("{$url}/resolve", ['resolution' => 'Replaced the pipe.'])->assertOk()
        ->assertJsonPath('data.status', 'resolved')
        ->assertJsonPath('data.resolution', 'Replaced the pipe.');

    $history = $this->actingAs($this->kim, 'sanctum')->getJson($url)->assertOk()->json('data.history');
    expect(array_column($history, 'body'))->toBe(['Started work.', 'Assigned to Mario.', 'Plumber comes tomorrow.', 'Marked as fixed. Replaced the pipe.'])
        ->and($history[0]['author'])->toBe('Carla');
});

it('lets only the owner or a Manager record the cost and cancel; the boarder may cancel until work starts', function () {
    $id = reportLeak()->json('data.id');
    $url = "/api/v1/maintenance-requests/{$id}";

    $this->actingAs($this->collector, 'sanctum')->putJson("{$url}/cost", ['repair_cost_centavos' => 150000])->assertForbidden();
    $this->postJson("{$url}/cancel", ['reason' => 'x'])->assertForbidden();
    $this->getJson($url)->assertJsonPath('data.can.set_cost', false)->assertJsonPath('data.can.cancel', false);

    $this->actingAs($this->manager, 'sanctum')->putJson("{$url}/cost", ['repair_cost_centavos' => 150000])->assertOk()
        ->assertJsonPath('data.repair_cost_centavos', 150000);
    $this->postJson("{$url}/cancel")->assertUnprocessable()->assertJsonValidationErrors(['reason']);

    // The boarder withdraws their own request while it is still new; after work starts they cannot.
    $this->actingAs($this->kim, 'sanctum')->postJson("{$url}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson("{$url}/notes", ['body' => 'Hello?'])->assertUnprocessable();

    $second = reportLeak()->json('data.id');
    MaintenanceRequest::whereKey($second)->update(['status' => MaintenanceStatus::InProgress]);
    $this->postJson("/api/v1/maintenance-requests/{$second}/cancel")->assertForbidden();
    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/maintenance-requests/{$second}/cancel", ['reason' => 'Not a repair: the boarder\'s own fan'])
        ->assertOk()->assertJsonPath('data.cancel_reason', 'Not a repair: the boarder\'s own fan');
});

it('lists a property\'s open requests with urgent ones first; staff can log one for a room or the whole place', function () {
    $normal = reportLeak(['urgency' => 'normal', 'description' => 'Loose cabinet door.'])->json('data.id');
    Date::setTestNow(now()->addMinute());
    $urgent = reportLeak()->json('data.id');
    Date::setTestNow(now()->addMinute());

    $base = "/api/v1/properties/{$this->property->id}/maintenance-requests";
    $logged = $this->actingAs($this->manager, 'sanctum')->postJson($base, ['category' => 'electrical', 'description' => 'Hallway light is out.'])
        ->assertCreated()->assertJsonPath('data.room', null)->assertJsonPath('data.reported_by.name', 'Mario')->json('data.id');
    $this->postJson($base, ['category' => 'other', 'description' => 'x', 'room_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors(['room_id']);

    expect(collect($this->getJson($base)->assertOk()->json('data'))->pluck('id')->all())->toBe([$urgent, $logged, $normal]);

    $this->postJson("/api/v1/maintenance-requests/{$normal}/resolve")->assertOk();
    expect(collect($this->getJson("{$base}?status=resolved")->json('data'))->pluck('id')->all())->toBe([$normal]);
    $this->getJson("{$base}?status=all")->assertJsonCount(3, 'data');
});

it('keeps requests to the reporter and the property\'s staff', function () {
    $id = reportLeak()->json('data.id');
    $otherOwner = User::factory()->owner()->create();
    $otherBoarder = User::factory()->boarder()->create();

    foreach ([$otherOwner, $otherBoarder] as $stranger) {
        $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/maintenance-requests/{$id}")->assertNotFound();
        $this->postJson("/api/v1/maintenance-requests/{$id}/notes", ['body' => 'hi'])->assertNotFound();
    }
    $this->actingAs($otherOwner, 'sanctum')->getJson("/api/v1/properties/{$this->property->id}/maintenance-requests")->assertNotFound();

    // The boarder cannot act as staff on their own request.
    $this->actingAs($this->kim, 'sanctum')->postJson("/api/v1/maintenance-requests/{$id}/start")->assertForbidden();
    $this->putJson("/api/v1/maintenance-requests/{$id}/cost", ['repair_cost_centavos' => 1])->assertForbidden();

    // After moving out they still see what they reported.
    $this->stay->update(['status' => 'ended', 'moved_out_on' => '2026-10-18']);
    $this->getJson("/api/v1/maintenance-requests/{$id}")->assertOk();
});

it('counts open and urgent requests per property for the home screen', function () {
    reportLeak();
    reportLeak(['urgency' => 'normal']);
    $done = reportLeak()->json('data.id');
    $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/maintenance-requests/{$done}/resolve")->assertOk();

    $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/me/maintenance-summary')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.property_id', $this->property->id)
        ->assertJsonPath('data.0.open_count', 2)
        ->assertJsonPath('data.0.urgent_count', 1);
    $this->actingAs(User::factory()->owner()->create(), 'sanctum')->getJson('/api/v1/me/maintenance-summary')->assertOk()->assertJsonCount(0, 'data');
});
