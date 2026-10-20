<?php

use App\Enums\CaretakerAccessLevel;
use App\Models\HouseRuleVersion;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->property = makeProperty($this->owner);
    $this->url = "/api/v1/properties/{$this->property->id}/house-rules";
    $this->rules = [
        ['category' => 'quiet_hours', 'body' => 'Keep quiet from 10:00 PM to 6:00 AM.'],
        ['category' => 'cleanliness', 'body' => 'Wash your own dishes the same day.'],
    ];
});

it('saves a draft, publishes it as version 1, then version 2, keeping version 1 readable', function () {
    $this->actingAs($this->owner, 'sanctum');
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.current', null)->assertJsonPath('data.draft', null);

    $this->putJson("{$this->url}/draft", ['rules' => $this->rules])->assertOk()
        ->assertJsonPath('data.is_draft', true)
        ->assertJsonPath('data.rules.1.category_label', 'Cleanliness');
    // Saving again replaces the draft; it never makes a second one.
    $this->putJson("{$this->url}/draft", ['rules' => array_reverse($this->rules)])->assertOk()->assertJsonPath('data.rules.0.category', 'cleanliness');
    expect(HouseRuleVersion::count())->toBe(1);

    $this->postJson("{$this->url}/publish")->assertOk()
        ->assertJsonPath('message', 'House rules version 1 published.')
        ->assertJsonPath('data.version', 1);
    $this->postJson("{$this->url}/publish")->assertUnprocessable()->assertJsonValidationErrors(['draft']);

    $this->putJson("{$this->url}/draft", ['rules' => [...$this->rules, ['category' => 'visitors', 'body' => 'Visitors leave by 9:00 PM.']], 'note' => 'Visitor hours added'])->assertOk();
    $this->getJson($this->url)->assertOk()
        ->assertJsonPath('data.current.version', 1)        // the draft is not in force yet
        ->assertJsonCount(3, 'data.draft.rules');
    $this->postJson("{$this->url}/publish")->assertOk()->assertJsonPath('data.version', 2);

    $show = $this->getJson($this->url)->assertOk();
    $show->assertJsonPath('data.current.version', 2)->assertJsonPath('data.draft', null)
        ->assertJsonPath('data.versions.0.note', 'Visitor hours added')
        ->assertJsonPath('data.versions.1.rules_count', 3); // 2 of the owner's + the overnight-visitor line from the settings
    $this->getJson("{$this->url}/versions/1")->assertOk()->assertJsonCount(3, 'data.rules')->assertJsonPath('data.rules.0.category', 'cleanliness');
    $this->getJson("{$this->url}/versions/9")->assertNotFound();

    $this->getJson('/api/v1/owner/audit-log?event=property.house_rules_published')->assertJsonCount(2, 'data');
});

it('will not publish an empty draft, and a discarded draft leaves the rules in force alone', function () {
    $this->actingAs($this->owner, 'sanctum');
    $this->putJson("{$this->url}/draft", ['rules' => []])->assertOk();
    $this->postJson("{$this->url}/publish")->assertUnprocessable()->assertJsonPath('errors.draft.0', 'Add at least one rule before publishing.');

    $this->putJson("{$this->url}/draft", ['rules' => [['category' => 'smoking', 'body' => 'x'], ['category' => 'safety', 'body' => '']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['rules.0.category', 'rules.1.body']);

    $this->putJson("{$this->url}/draft", ['rules' => $this->rules])->assertOk();
    $this->postJson("{$this->url}/publish")->assertOk();
    $this->putJson("{$this->url}/draft", ['rules' => [$this->rules[0]]])->assertOk();
    $this->deleteJson("{$this->url}/draft")->assertOk();
    $this->getJson($this->url)->assertJsonPath('data.draft', null)->assertJsonCount(3, 'data.current.rules');
});

it('lets a Manager edit and publish; a Collector only reads; strangers see nothing', function () {
    $manager = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Manager)->create();
    $collector = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Collector)->create();
    assignCaretaker($this->property, $manager, 'manager');
    assignCaretaker($this->property, $collector, 'collector');

    $this->actingAs($manager, 'sanctum')->putJson("{$this->url}/draft", ['rules' => $this->rules])->assertOk();
    $this->postJson("{$this->url}/publish")->assertOk();

    $this->actingAs($collector, 'sanctum')->getJson($this->url)->assertOk()->assertJsonPath('data.current.version', 1);
    $this->putJson("{$this->url}/draft", ['rules' => $this->rules])->assertForbidden();
    $this->postJson("{$this->url}/publish")->assertForbidden();

    $this->actingAs(User::factory()->owner()->create(), 'sanctum')->getJson($this->url)->assertNotFound();
});

it('shows the rules in force on the listing, not the draft', function () {
    $this->property->forceFill(['latitude' => 14.6091, 'longitude' => 120.9894, 'is_published' => true, 'published_at' => now()])->save();
    $listing = "/api/v1/listings/{$this->property->id}";
    $this->getJson($listing)->assertOk()->assertJsonPath('data.listing.house_rules', null);

    $this->actingAs($this->owner, 'sanctum')->putJson("{$this->url}/draft", ['rules' => $this->rules])->assertOk();
    $this->postJson("{$this->url}/publish")->assertOk();
    $this->putJson("{$this->url}/draft", ['rules' => [$this->rules[0]]])->assertOk();

    $this->app['auth']->forgetGuards();
    $this->getJson($listing)->assertOk()
        ->assertJsonPath('data.listing.house_rules.version', 1)
        ->assertJsonCount(3, 'data.listing.house_rules.rules')
        ->assertJsonPath('data.listing.house_rules.rules.0.body', 'Keep quiet from 10:00 PM to 6:00 AM.');
});
