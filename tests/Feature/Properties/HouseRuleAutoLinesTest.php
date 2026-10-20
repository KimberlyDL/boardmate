<?php

use App\Enums\NotificationEvent;
use App\Models\HouseRuleVersion;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\Notifications\DormLinkNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->owner = User::factory()->owner()->create();
    $this->property = makeProperty($this->owner);
    $this->rules = "/api/v1/properties/{$this->property->id}/house-rules";
    $this->settings = "/api/v1/properties/{$this->property->id}/settings";
    $this->actingAs($this->owner, 'sanctum');
});

function ownRules(array $bodies = ['Keep quiet from 10:00 PM to 6:00 AM.']): void
{
    test()->putJson(test()->rules.'/draft', ['rules' => array_map(fn ($b) => ['category' => 'quiet_hours', 'body' => $b], $bodies)])->assertOk();
    test()->postJson(test()->rules.'/publish')->assertOk();
}

/** [category, body, is_auto] of the rules in force */
function rulesInForce(): array
{
    return collect(test()->getJson(test()->rules)->json('data.current.rules'))->map(fn ($r) => [$r['category'], $r['body'], $r['is_auto']])->all();
}

it('adds the curfew and visitor lines after the owner\'s own rules when publishing', function () {
    $this->putJson($this->settings, ['curfew_time' => '22:00', 'visitor_hours_start' => '08:00', 'visitor_hours_end' => '21:00'])->assertOk();
    $this->getJson($this->rules)->assertJsonCount(3, 'data.automatic');
    HouseRuleVersion::query()->delete(); // start from no rules, as if the settings came first

    ownRules();
    expect(rulesInForce())->toBe([
        ['quiet_hours', 'Keep quiet from 10:00 PM to 6:00 AM.', false],
        ['curfew', 'The curfew is 10:00 PM. If you will be late, ask in advance in the app.', true],
        ['visitors', 'Visitors are welcome from 8:00 AM to 9:00 PM.', true],
        ['visitors', 'Overnight visitors need approval first, up to 3 nights a month for each boarder.', true],
    ]);
});

it('publishes a new version by itself when the curfew changes, keeping the owner\'s rules and draft', function () {
    $kim = User::factory()->boarder()->create();
    Tenancy::factory()->forUnit($this->property->units()->first())->create(['tenant_id' => $kim->id]);
    ownRules();
    $this->putJson($this->rules.'/draft', ['rules' => [['category' => 'other', 'body' => 'Work in progress.']]])->assertOk();

    $this->putJson($this->settings, ['curfew_time' => '22:00'])->assertOk()
        ->assertJsonPath('message', 'Settings saved. House rules version 2 published; boarders are asked to accept it.');
    $this->getJson($this->rules)->assertOk()
        ->assertJsonPath('data.current.version', 2)
        ->assertJsonPath('data.current.note', 'Curfew or visitor settings changed')
        ->assertJsonPath('data.draft.rules.0.body', 'Work in progress.');
    expect(rulesInForce()[0][1])->toBe('Keep quiet from 10:00 PM to 6:00 AM.')
        ->and(collect(rulesInForce())->pluck(1))->toContain('The curfew is 10:00 PM. If you will be late, ask in advance in the app.');
    Notification::assertSentTo($kim, DormLinkNotification::class, fn ($n) => $n->event === NotificationEvent::HouseRulesUpdated && $n->data['version'] === 2);

    // The same curfew again, or another setting: no new version.
    $this->putJson($this->settings, ['curfew_time' => '22:00'])->assertOk()->assertJsonPath('message', 'Settings saved.');
    $this->putJson($this->settings, ['grace_days' => 5])->assertOk()->assertJsonPath('message', 'Settings saved.');
    expect(HouseRuleVersion::published()->count())->toBe(2);

    // Turning the curfew off (a reset) takes its line out in version 3.
    $this->postJson("{$this->settings}/reset", ['sections' => ['curfew']])->assertOk()
        ->assertJsonPath('message', 'Back to the default settings. House rules version 3 published; boarders are asked to accept it.');
    expect(collect(rulesInForce())->pluck(0))->not->toContain('curfew');
});

it('gives a place without rules its first version when it sets a curfew', function () {
    $this->putJson($this->settings, ['curfew_time' => '21:30', 'overnight_requires_approval' => false, 'overnight_limit_per_month' => 1])->assertOk();

    expect(rulesInForce())->toBe([
        ['curfew', 'The curfew is 9:30 PM. If you will be late, ask in advance in the app.', true],
        ['visitors', 'Overnight visitors: up to 1 night a month for each boarder. Register them in the app.', true],
    ]);
});
