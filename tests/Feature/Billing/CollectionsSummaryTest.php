<?php

use App\Enums\BillKind;
use App\Enums\BillLineKind;
use App\Enums\CaretakerAccessLevel;
use App\Enums\PaymentMethod;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\CollectionNote;
use App\Models\Payment;
use App\Models\Tenancy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-11-04 10:00', 'Asia/Manila'));

    $this->owner = User::factory()->owner()->create();
    $this->house = makeProperty($this->owner, ['details' => ['name' => 'Santos House']]);
    $this->annex = makeProperty($this->owner, ['details' => ['name' => 'Annex']]);
    $this->collector = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Collector)->create();
    assignCaretaker($this->house, $this->collector, 'collector');

    [$bed1, $bed2] = $this->house->units()->get();
    $this->kim = User::factory()->boarder()->create(['name' => 'Kim']);
    $this->ben = User::factory()->boarder()->create(['name' => 'Ben']);
    $kimStay = Tenancy::factory()->forUnit($bed1)->movedInOn('2026-07-25')->create(['tenant_id' => $this->kim->id]);
    $benStay = Tenancy::factory()->forUnit($bed2)->movedInOn('2026-09-25')->create(['tenant_id' => $this->ben->id]);

    $owed = function (Tenancy $stay, string $due, int $amount) {
        $bill = Bill::factory()->forTenancy($stay)->create(['kind' => BillKind::Periodic, 'issued_on' => $due, 'due_on' => $due]);
        BillLine::factory()->forBill($bill)->create(['kind' => BillLineKind::Rent, 'amount_centavos' => $amount]);
    };
    $owed($kimStay, '2026-08-25', 180000);   // 71 days overdue
    $owed($kimStay, '2026-10-25', 180000);
    $owed($benStay, '2026-10-30', 90000);    // 5 days overdue
    $owed($benStay, '2026-11-07', 200000);   // due in 3 days
    CollectionNote::create(['property_id' => $this->house->id, 'payer_id' => $this->kim->id, 'author_id' => $this->collector->id,
        'note' => 'Will pay on Friday', 'promised_on' => '2026-11-06']);

    // Received: two cash payments this week, one last week, a deposit used at settlement and a pending report.
    Payment::factory()->forPayer($this->house, $this->ben)->confirmed()->create(['amount_centavos' => 50000, 'paid_on' => '2026-11-01']);
    Payment::factory()->forPayer($this->house, $this->ben)->confirmed()->create(['amount_centavos' => 30000, 'paid_on' => '2026-11-04']);
    Payment::factory()->forPayer($this->house, $this->ben)->confirmed()->create(['amount_centavos' => 99000, 'paid_on' => '2026-10-28']);
    Payment::factory()->forPayer($this->house, $this->ben)->confirmed()->create(['amount_centavos' => 40000, 'paid_on' => '2026-11-03', 'method' => PaymentMethod::Deposit]);
    Payment::factory()->forPayer($this->house, $this->kim)->create(['amount_centavos' => 70000, 'paid_on' => '2026-11-04']);
});

afterEach(fn () => Date::setTestNow());

it('shows a collector what is due, who is behind and what came in this week', function () {
    $data = $this->actingAs($this->collector, 'sanctum')->getJson('/api/v1/me/collections')->assertOk()->json('data');

    expect($data['properties'])->toBe([['id' => $this->house->id, 'name' => 'Santos House']])   // only where they collect
        ->and($data['week'])->toBe(['from' => '2026-10-29', 'to' => '2026-11-04'])
        ->and($data['received']['payments'])->toBe(2)
        ->and($data['received']['centavos'])->toBe(80000)   // not the deposit, not last week, not the pending report
        ->and($data['received']['by_day'])->toHaveCount(7)
        ->and($data['received']['by_day'][3])->toBe(['date' => '2026-11-01', 'payments' => 1, 'centavos' => 50000])
        ->and($data['due_soon'])->toBe(['bills' => 1, 'centavos' => 200000])
        ->and($data['overdue'])->toMatchArray(['payers' => 2, 'bills' => 3, 'centavos' => 450000])
        ->and($data['behind']['days_1_30'])->toBe(['payers' => 1, 'centavos' => 90000])
        ->and($data['behind']['days_61_90'])->toBe(['payers' => 1, 'centavos' => 360000])
        ->and($data['proofs_waiting'])->toBe(1)
        ->and($data['most_behind'][0])->toMatchArray(['payer' => ['id' => $this->kim->id, 'name' => 'Kim', 'phone' => $this->kim->phone],
            'days_overdue' => 71, 'overdue_centavos' => 360000, 'promised_on' => '2026-11-06'])
        ->and($data)->not->toHaveKeys(['billed', 'collected', 'deposits', 'repair_costs']);
});

it('agrees with the overdue list', function () {
    $summary = $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/me/collections?property_id={$this->house->id}")->json('data.overdue');
    $list = $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/properties/{$this->house->id}/collections")->json('meta');

    expect($summary['payers'])->toBe($list['payers'])
        ->and($summary['centavos'])->toBe($list['overdue_total_centavos']);
});

it('covers every property of the owner, or one', function () {
    $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/me/collections')->assertOk()
        ->assertJsonPath('data.properties.0.name', 'Annex')
        ->assertJsonCount(2, 'data.properties');

    $this->actingAs($this->collector, 'sanctum')->getJson("/api/v1/me/collections?property_id={$this->annex->id}")
        ->assertUnprocessable()->assertJsonValidationErrors(['property_id']);
});

it('is only for people who follow up payments', function () {
    $this->actingAs($this->kim, 'sanctum')->getJson('/api/v1/me/collections')->assertForbidden();
});
