<?php

use App\Enums\LateEntryStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\NoticeStatus;
use App\Enums\UnitStatus;
use App\Enums\VisitorStatus;
use App\Models\CurfewViolation;
use App\Models\LateEntryRequest;
use App\Models\MaintenanceRequest;
use App\Models\Tenancy;
use App\Models\TenancyNotice;
use App\Models\User;
use App\Models\Visitor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Manila'));
    $this->owner = User::factory()->owner()->create();
    $this->property = makeProperty($this->owner, ['details' => ['name' => 'Santos House'], 'units' => ['count' => 3, 'rent_centavos' => 200000]]);
    [$this->bed1, $this->bed2, $this->bed3] = $this->property->units()->get();
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $this->ben = User::factory()->boarder()->create(['name' => 'Ben']);
    $this->kimStay = Tenancy::factory()->forUnit($this->bed1)->movedInOn('2026-09-01')->create(['tenant_id' => $this->kim->id]);
    $this->benStay = Tenancy::factory()->forUnit($this->bed2)->movedInOn('2026-09-15')->create(['tenant_id' => $this->ben->id]);
});

afterEach(fn () => Date::setTestNow());

/** Download a report as the owner; rows without the header. */
function opsCsv(string $report, array $query = []): array
{
    $url = test()->actingAs(test()->owner, 'sanctum')->getJson("/api/v1/reports/{$report}/link?".http_build_query($query))->assertOk()->json('data.url');
    auth()->forgetGuards();

    return array_slice(array_map('str_getcsv', array_filter(explode("\n", substr(test()->get($url)->assertOk()->streamedContent(), 3)))), 1);
}

it('lists every place with its state, tenant and rent today', function () {
    $this->bed1->forceFill(['status' => UnitStatus::Occupied])->save();
    $this->bed2->forceFill(['status' => UnitStatus::Leaving])->save();
    $this->bed3->forceFill(['not_ready' => true])->save();
    $this->benStay->update(['status' => 'notice_given', 'end_on' => '2026-11-15']);

    $rows = opsCsv('occupancy');
    expect(array_map(fn ($r) => [$r[3], $r[5], $r[6], $r[8], $r[9]], $rows))->toBe([
        [$this->bed1->label, 'Occupied', 'Kim', '', '2000.00'],
        [$this->bed2->label, 'Leaving', 'Ben', '2026-11-15', '2000.00'],
        [$this->bed3->label, 'Not ready', '', '', '2000.00'],
    ]);
});

it('lists stays ending and last days asked for in the next 30 days by default', function () {
    $this->benStay->update(['status' => 'notice_given', 'end_on' => '2026-11-15']);
    TenancyNotice::factory()->forTenancy($this->kimStay)->create(['end_on' => '2026-11-05', 'status' => NoticeStatus::Pending]);
    TenancyNotice::factory()->forTenancy($this->kimStay)->create(['end_on' => '2027-01-31', 'status' => NoticeStatus::Declined]); // not counted

    expect(array_map(fn ($r) => [$r[1], $r[2], $r[3], $r[6]], opsCsv('move_outs')))->toBe([
        ['2026-11-05', 'Last day asked for', 'Kim', 'Waiting for approval'],
        ['2026-11-15', 'Last day of stay', 'Ben', 'Approved'],
    ]);
    $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/reports/move_outs/link')
        ->assertJsonPath('data.filename', 'dormlink-move_outs-santos-house-2026-10-20_to_2026-11-19.csv');
});

it('exports repairs, visitors and the curfew log for the period', function () {
    $repair = MaintenanceRequest::factory()->forTenancy($this->kimStay)->status(MaintenanceStatus::Closed)
        ->create(['repair_cost_centavos' => 150000, 'resolution' => 'Replaced the pipe.', 'resolved_at' => '2026-10-07 09:00:00']);
    $repair->forceFill(['created_at' => '2026-10-05 02:00:00'])->save(); // Oct 5, 10:00 AM in Manila
    Visitor::factory()->forTenancy($this->kimStay)->overnight(2, VisitorStatus::Approved)->create(['name' => 'Ana', 'visit_on' => '2026-10-10', 'over_limit' => true]);
    Visitor::factory()->forTenancy($this->kimStay)->create(['name' => 'Old', 'visit_on' => '2026-09-10']); // before the period
    LateEntryRequest::factory()->forTenancy($this->kimStay)->create(['on_date' => '2026-10-12', 'status' => LateEntryStatus::Approved]);
    CurfewViolation::create(['property_id' => $this->property->id, 'tenancy_id' => $this->benStay->id, 'on_date' => '2026-10-12', 'arrived_at' => '23:40']);

    $repairs = opsCsv('repairs');
    expect($repairs)->toHaveCount(1)
        ->and(array_slice($repairs[0], 0, 4))->toBe(['Santos House', '2026-10-05', "Room {$this->kimStay->room->label()}", 'Plumbing'])
        ->and([$repairs[0][7], $repairs[0][9], $repairs[0][10], $repairs[0][12]])->toBe(['Closed', '2026-10-07', '2', '1500.00']);

    $visitors = opsCsv('visitors');
    expect($visitors)->toHaveCount(1)->and([$visitors[0][2], $visitors[0][3], $visitors[0][5], $visitors[0][8]])->toBe(['Ana', 'Kim', '2', 'Yes']);

    expect(array_map(fn ($r) => [$r[1], $r[2], $r[3]], opsCsv('curfew')))->toBe([
        ['2026-10-12', 'Late-entry request', 'Kim'],
        ['2026-10-12', 'Late arrival logged', 'Ben'],
    ]);
});
