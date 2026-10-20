<?php

use App\Enums\NotificationEvent;
use App\Models\BookingApplication;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\Notifications\DormLinkNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-10-25 10:00', 'Asia/Manila'));
    Notification::fake();
    Storage::fake('local');

    $this->owner = User::factory()->owner()->create();
    $this->property = makeProperty($this->owner, ['units' => ['count' => 3, 'rent_centavos' => 200000]]);
    $this->property->forceFill(['latitude' => 14.6, 'longitude' => 121.0, 'is_published' => true, 'published_at' => now()])->save();
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $this->rulesUrl = "/api/v1/properties/{$this->property->id}/house-rules";
});

afterEach(fn () => Date::setTestNow());

/** The owner publishes the next version of the rules. */
function publishRules(string $body = 'Keep quiet from 10:00 PM to 6:00 AM.', ?string $note = null): void
{
    test()->actingAs(test()->owner, 'sanctum')->putJson(test()->rulesUrl.'/draft', ['rules' => [['category' => 'quiet_hours', 'body' => $body]], 'note' => $note])->assertOk();
    test()->postJson(test()->rulesUrl.'/publish')->assertOk();
}

/** Kim applies (accepting $version), and the owner reserves the first bed. Returns the application id. */
function applyAndReserve(?int $version): int
{
    $id = test()->actingAs(test()->kim, 'sanctum')->postJson('/api/v1/listings/'.test()->property->id.'/applications', [
        'planned_move_in_on' => '2026-10-27', 'contact_phone' => '0917 123 4567', 'house_rules_version' => $version,
    ])->assertCreated()->json('data.id');
    test()->actingAs(test()->owner, 'sanctum')->postJson("/api/v1/applications/{$id}/approve", ['unit_id' => test()->property->units()->first()->id])->assertOk();

    return $id;
}

function rulesMoveIn(array $extra = []): array
{
    return [
        'moved_in_on' => '2026-10-25',
        'emergency_contact' => ['name' => 'Marta Santos', 'phone' => '0917 555 0000'],
        'deposit' => ['amount_centavos' => 200000, 'method' => 'cash'],
        'first_rent' => ['amount_centavos' => 200000, 'method' => 'cash'],
    ] + $extra;
}

it('asks the boarder to accept the rules in force when applying, and carries that acceptance to the move-in', function () {
    publishRules();

    $apply = fn (?int $version) => $this->actingAs($this->kim, 'sanctum')->postJson("/api/v1/listings/{$this->property->id}/applications", [
        'planned_move_in_on' => '2026-10-27', 'contact_phone' => '0917 123 4567', 'house_rules_version' => $version,
    ]);
    $apply(null)->assertUnprocessable()->assertJsonPath('errors.house_rules_version.0', 'Read and accept the house rules first.');
    $apply(0)->assertUnprocessable()->assertJsonPath('errors.house_rules_version.0', 'The house rules changed. Read version 1 and accept it.');

    $id = applyAndReserve(1);
    expect(BookingApplication::find($id)->house_rules_version)->toBe(1); // the move-in sheet reads it
    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/applications/{$id}/move-in", rulesMoveIn())->assertCreated();

    $tenancy = Tenancy::sole();
    expect($tenancy->house_rules_accepted_at)->not->toBeNull();
    $this->actingAs($this->kim, 'sanctum')->getJson("/api/v1/tenancies/{$tenancy->id}/house-rules")->assertOk()
        ->assertJsonPath('data.current.version', 1)
        ->assertJsonPath('data.needs_acceptance', false)
        ->assertJsonPath('data.acceptances.0.method', 'app')
        ->assertJsonPath('data.acceptances.0.recorded_by', null);
});

it('needs staff to confirm an in-person acceptance when the rules changed after applying, or for a walk-in', function () {
    publishRules();
    $id = applyAndReserve(1);
    publishRules('No cooking in the rooms.', 'Cooking rule added');

    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/applications/{$id}/move-in", rulesMoveIn())
        ->assertUnprocessable()
        ->assertJsonPath('errors.house_rules_accepted_in_person.0', 'Kim accepted older house rules when applying. Confirm they accepted version 2 in person.');
    expect(Tenancy::count())->toBe(0);

    $this->postJson("/api/v1/applications/{$id}/move-in", rulesMoveIn(['house_rules_accepted_in_person' => true]))->assertCreated();
    $this->getJson('/api/v1/tenancies/'.Tenancy::sole()->id.'/house-rules')->assertOk()
        ->assertJsonPath('data.acceptances.0.version', 2)
        ->assertJsonPath('data.acceptances.0.method', 'in_person')
        ->assertJsonPath('data.acceptances.0.recorded_by', $this->owner->name);

    // A walk-in never accepted in the app.
    $ben = User::factory()->boarder()->create(['name' => 'Ben']);
    $walkIn = rulesMoveIn(['email' => $ben->email, 'unit_id' => $this->property->units()->get()[1]->id]);
    $this->postJson("/api/v1/properties/{$this->property->id}/tenancies", $walkIn)
        ->assertUnprocessable()->assertJsonPath('errors.house_rules_accepted_in_person.0', 'Confirm Ben accepted the house rules (version 2) in person.');
    $this->postJson("/api/v1/properties/{$this->property->id}/tenancies", $walkIn + ['house_rules_accepted_in_person' => true])->assertCreated();
});

it('needs nothing at a place without rules', function () {
    $id = applyAndReserve(null);
    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/applications/{$id}/move-in", rulesMoveIn())->assertCreated();

    expect(Tenancy::sole()->house_rules_accepted_at)->toBeNull();
    $this->actingAs($this->kim, 'sanctum')->getJson('/api/v1/tenancies/'.Tenancy::sole()->id.'/house-rules')
        ->assertJsonPath('data.current', null)->assertJsonPath('data.needs_acceptance', false);
});

it('tells current tenants about a new version and lets them accept it, never an older one', function () {
    publishRules();
    $id = applyAndReserve(1);
    $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/applications/{$id}/move-in", rulesMoveIn())->assertCreated();
    $tenancy = Tenancy::sole();
    $url = "/api/v1/tenancies/{$tenancy->id}/house-rules";

    publishRules('Visitors leave by 9:00 PM.', 'Visitor hours added');
    Notification::assertSentTo($this->kim, DormLinkNotification::class,
        fn ($n) => $n->event === NotificationEvent::HouseRulesUpdated && $n->data['version'] === 2 && $n->data['note'] === 'Visitor hours added');

    $this->actingAs($this->kim, 'sanctum')->getJson($url)->assertJsonPath('data.needs_acceptance', true)->assertJsonPath('data.current.version', 2);
    $this->postJson("{$url}/accept", ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors(['version']);
    $this->postJson("{$url}/accept", ['version' => 2])->assertOk();
    $this->postJson("{$url}/accept", ['version' => 2])->assertOk(); // twice is harmless
    $this->getJson($url)->assertJsonPath('data.needs_acceptance', false)->assertJsonCount(2, 'data.acceptances');

    // Staff of the place read the record; nobody else sees it, and only the tenant accepts.
    $this->actingAs($this->owner, 'sanctum')->getJson($url)->assertOk();
    $this->postJson("{$url}/accept", ['version' => 2])->assertNotFound();
    $this->actingAs(User::factory()->boarder()->create(), 'sanctum')->getJson($url)->assertNotFound();
});
