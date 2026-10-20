<?php

use App\Enums\BillKind;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Tenancy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/*
| The design doc's A10 scenarios. Rooms with unpaid shared bills and the
| Collector not seeing "collected this month" are tested in DashboardTest;
| streaming is how every report is written (ReportController::download).
| These are the two not covered there.
*/

beforeEach(fn () => Date::setTestNow(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Manila')));
afterEach(fn () => Date::setTestNow());

it('A10: the dashboard, the overdue list, the tenant\'s home and the outstanding report agree', function () {
    $owner = User::factory()->owner()->create();
    $property = makeProperty($owner, ['units' => ['count' => 2, 'rent_centavos' => 200000]]);
    $kim = User::factory()->boarder()->create();
    $stay = Tenancy::factory()->forUnit($property->units()->first())->movedInOn('2026-09-01')->create(['tenant_id' => $kim->id]);

    // Two overdue bills, one partly paid, and one not yet due.
    $lines = [];
    foreach ([['2026-10-01', 200000], ['2026-10-10', 30000], ['2026-10-25', 200000]] as [$due, $amount]) {
        $bill = Bill::factory()->forTenancy($stay)->create(['kind' => BillKind::Periodic, 'issued_on' => '2026-09-25', 'due_on' => $due]);
        $lines[] = BillLine::factory()->forBill($bill)->create(['amount_centavos' => $amount]);
    }
    $payment = Payment::factory()->forPayer($property, $kim)->confirmed()->create(['amount_centavos' => 50000, 'paid_on' => '2026-10-02']);
    PaymentAllocation::create(['payment_id' => $payment->id, 'bill_line_id' => $lines[0]->id, 'amount_centavos' => 50000]);

    $dashboard = $this->actingAs($owner, 'sanctum')->getJson("/api/v1/properties/{$property->id}/dashboard")->json('data');
    $overdueList = $this->getJson("/api/v1/properties/{$property->id}/collections")->json('meta.overdue_total_centavos');
    $url = $this->getJson('/api/v1/reports/outstanding/link')->json('data.url');
    auth()->forgetGuards();
    $csv = array_slice(array_map('str_getcsv', array_filter(explode("\n", substr($this->get($url)->streamedContent(), 3)))), 1);
    $home = $this->actingAs($kim, 'sanctum')->getJson('/api/v1/me/home')->json('data.money');

    $overdueInCsv = (int) round(collect($csv)->filter(fn ($r) => $r[8] !== 'Not yet due')->sum(fn ($r) => (float) $r[9]) * 100);
    $owedInCsv = (int) round(collect($csv)->sum(fn ($r) => (float) $r[9]) * 100);
    expect($dashboard['overdue']['centavos'])->toBe(180000) // 150,000 left on the first + 30,000
        ->and($overdueList)->toBe(180000)
        ->and($overdueInCsv)->toBe(180000)
        ->and($home['overdue_centavos'])->toBe(180000)
        ->and($home['owed_centavos'])->toBe(380000)
        ->and($owedInCsv)->toBe(380000)
        ->and($dashboard['due_this_week']['centavos'])->toBe(200000); // due on the 25th, within the next 7 days
});

it('A10: a new owner sees empty lists, not rows of zeros', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner, 'sanctum')->getJson('/api/v1/me/dashboard')->assertOk()
        ->assertJsonPath('data.properties', [])
        ->assertJsonPath('data.recent_activity', []);
    $this->getJson('/api/v1/reports')->assertOk()->assertJsonCount(0, 'data');

    // A first property with no tenants: no bills, nothing overdue, every place free.
    $property = makeProperty($owner, ['units' => ['count' => 2, 'rent_centavos' => 200000]]);
    $this->getJson("/api/v1/properties/{$property->id}/dashboard")->assertOk()
        ->assertJsonPath('data.occupancy.available', 2)
        ->assertJsonPath('data.overdue', ['payers' => 0, 'centavos' => 0])
        ->assertJsonPath('data.unpaid_shared_bills', []);
});
