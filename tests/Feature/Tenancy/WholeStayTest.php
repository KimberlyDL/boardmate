<?php

use App\Enums\BillKind;
use App\Enums\TenancyStatus;
use App\Enums\UnitStatus;
use App\Models\Bill;
use App\Models\Tenancy;
use App\Models\TenancyPayment;
use App\Models\User;
use App\Services\Billing\PaymentLedger;
use App\Services\Billing\StatementBuilder;
use App\Services\Pricing\PriceBook;
use App\Services\Properties\UnitStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

/** Run the daily jobs as of a Manila day, the way the 09:00 schedule does. */
function dailyOn(string $day): void
{
    Date::setTestNow(CarbonImmutable::parse("{$day} 09:00", 'Asia/Manila'));
    test()->artisan('dormlink:daily')->assertSuccessful();
}

it('takes one stay from move-in to a settled deposit, through the daily jobs (worked example)', function () {
    Notification::fake();

    // Sep 1: a ₱4,000 bedspace with ₱100 water. Kim moves in Sep 25, deposit ₱4,000.
    Date::setTestNow(CarbonImmutable::parse('2026-09-01 10:00', 'Asia/Manila'));
    $owner = User::factory()->owner()->create();
    $property = makeProperty($owner, ['units' => ['count' => 1, 'rent_centavos' => 400000]]);
    $water = $property->utilityAccounts()->create(['type' => 'water', 'name' => 'Water', 'method' => 'fixed_per_bedspace', 'billed_by' => 'owner']);
    app(PriceBook::class)->set($water, 10000, null, $owner);
    $unit = $property->units()->first();
    $kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $tenancy = Tenancy::factory()->forUnit($unit)->movedInOn('2026-09-25')->create(['tenant_id' => $kim->id]);
    TenancyPayment::factory()->create(['tenancy_id' => $tenancy->id, 'amount_centavos' => 400000, 'received_on' => '2026-09-25']);
    app(UnitStatusService::class)->move($unit, UnitStatus::Occupied);
    $t = "/api/v1/tenancies/{$tenancy->id}";

    // Oct 25: bill #1 (rent Oct 26 – Nov 25 + water Sep 26 – Oct 25 = ₱4,100); she pays it.
    dailyOn('2026-10-25');
    $this->actingAs($owner, 'sanctum')->postJson("/api/v1/properties/{$property->id}/payments", [
        'payer_id' => $kim->id, 'amount_centavos' => 410000, 'method' => 'cash', 'paid_on' => '2026-10-25',
    ])->assertCreated();

    // Nov 1: notice for Nov 25, approved.
    Date::setTestNow(CarbonImmutable::parse('2026-11-01 10:00', 'Asia/Manila'));
    $this->actingAs($kim, 'sanctum')->postJson("{$t}/notice", ['end_on' => '2026-11-25'])->assertCreated();
    $this->actingAs($owner, 'sanctum')->postJson("{$t}/notice/approve")->assertOk();

    // Nov 25 would start a period after her last day: no bill. Nov 26: past the last day, flagged.
    dailyOn('2026-11-25');
    dailyOn('2026-11-26');
    expect(Bill::where('kind', BillKind::Periodic)->count())->toBe(1)
        ->and($unit->fresh()->status)->toBe(UnitStatus::Overstaying);

    // Nov 27: she left on the 26th. Final bill: water Oct 26 – Nov 25, ₱100.
    Date::setTestNow(CarbonImmutable::parse('2026-11-27 10:00', 'Asia/Manila'));
    $this->postJson("{$t}/move-out", ['moved_out_on' => '2026-11-26'])->assertOk()
        ->assertJsonPath('data.unit_status', 'available')
        ->assertJsonPath('data.final_bill.total_centavos', 10000);

    // Settlement: ₱300 damage. The deposit pays ₱400 (water + damage); ₱3,600 is refunded.
    $this->postJson("{$t}/settlement", [
        'deductions' => [['description' => 'Broken chair', 'amount_centavos' => 30000, 'reason' => 'Found at move-out']],
        'refund_method' => 'e_wallet',
    ])->assertCreated()
        ->assertJsonPath('data.applied_centavos', 40000)
        ->assertJsonPath('data.refund_centavos', 360000)
        ->assertJsonPath('data.still_owed_centavos', 0);

    // Everything adds up: nothing owed, no credit, the statement closes at ₱0, and the follow-up job leaves her alone.
    expect($tenancy->fresh()->status)->toBe(TenancyStatus::Settled)
        ->and(app(PaymentLedger::class)->summary($property->id, $kim->id))->toBe(['owed_centavos' => 0, 'credit_centavos' => 0])
        ->and(app(StatementBuilder::class)->build($property->id, $kim->id)['closing_centavos'])->toBe(0);
    dailyOn('2026-11-30');
    $this->getJson("/api/v1/properties/{$property->id}/collections")->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($kim, 'sanctum')->getJson('/api/v1/me/tenancies?past=1')->assertJsonPath('data.0.is_settled', true);
});
