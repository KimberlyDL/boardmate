<?php

use App\Models\MaintenanceRequest;
use App\Models\Tenancy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

/*
| The design doc's D1 and D3 scenario tables. Most are covered where the
| feature is tested (MaintenanceRequestTest, MaintenanceFollowUpTest,
| MaintenanceNotificationsTest, HouseRulesTest, HouseRuleAcceptanceTest);
| these are the two not covered there.
*/

beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Manila'));
    Notification::fake();
    $this->owner = User::factory()->owner()->create();
    $this->property = makeProperty($this->owner, ['units' => ['count' => 2, 'rent_centavos' => 200000]]);
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $this->stay = Tenancy::factory()->forUnit($this->property->units()->first())->movedInOn('2026-09-01')->create(['tenant_id' => $this->kim->id]);
});

afterEach(fn () => Date::setTestNow());

it('D1: a request still open when the boarder moves out stays on the owner\'s list and can still be finished', function () {
    $request = MaintenanceRequest::factory()->forTenancy($this->stay)->create();
    $this->stay->update(['status' => 'ended', 'moved_out_on' => '2026-10-18']);

    $this->actingAs($this->owner, 'sanctum')
        ->getJson("/api/v1/properties/{$this->property->id}/maintenance-requests")->assertOk()
        ->assertJsonPath('data.0.id', $request->id);
    $this->postJson("/api/v1/maintenance-requests/{$request->id}/resolve", ['resolution' => 'Fixed after move-out.'])->assertOk();
    // Damage is settled from the deposit (C8); a charge is still possible, as for any past stay.
    $this->getJson("/api/v1/maintenance-requests/{$request->id}")->assertJsonPath('data.can.charge', true);
});

it('D3: in a dispute, the record shows which version the boarder accepted, when and how, and that version\'s text', function () {
    $rules = "/api/v1/properties/{$this->property->id}/house-rules";
    $this->actingAs($this->owner, 'sanctum')->putJson("{$rules}/draft", ['rules' => [['category' => 'visitors', 'body' => 'Visitors leave by 10:00 PM.']]])->assertOk();
    $this->postJson("{$rules}/publish")->assertOk();
    $this->actingAs($this->kim, 'sanctum')->postJson("/api/v1/tenancies/{$this->stay->id}/house-rules/accept", ['version' => 1])->assertOk();

    Date::setTestNow(CarbonImmutable::parse('2026-11-05 09:00', 'Asia/Manila'));
    $this->actingAs($this->owner, 'sanctum')->putJson("{$rules}/draft", ['rules' => [['category' => 'visitors', 'body' => 'Visitors leave by 9:00 PM.']], 'note' => 'Earlier visitor hours'])->assertOk();
    $this->postJson("{$rules}/publish")->assertOk();

    // Kim has not accepted version 2 yet: nothing is blocked, the record shows what she did accept.
    $record = $this->getJson("/api/v1/tenancies/{$this->stay->id}/house-rules")->assertOk()
        ->assertJsonPath('data.needs_acceptance', true)
        ->assertJsonPath('data.acceptances.0.version', 1)
        ->assertJsonPath('data.acceptances.0.method', 'app')
        ->json('data.acceptances.0');
    expect(CarbonImmutable::parse($record['accepted_at'])->setTimezone('Asia/Manila')->toDateString())->toBe('2026-10-18');

    // Which version was in force on a date: the version list's publish dates, and the old text is kept.
    $versions = $this->getJson($rules)->json('data.versions');
    expect(array_column($versions, 'version'))->toBe([2, 1])
        ->and(CarbonImmutable::parse($versions[1]['published_at'])->setTimezone('Asia/Manila')->toDateString())->toBe('2026-10-18');
    $this->getJson("{$rules}/versions/1")->assertJsonPath('data.rules.0.body', 'Visitors leave by 10:00 PM.');
});
