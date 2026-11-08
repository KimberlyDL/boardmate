<?php

use App\Enums\CaretakerAccessLevel;
use App\Models\Payment;
use App\Models\Tenancy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;

/** Reports and admin exports as CSV, Excel or PDF, and their preview. */
beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Manila'));
    Notification::fake();
    $this->owner = User::factory()->owner()->create();
    $this->collector = User::factory()->caretakerFor($this->owner, CaretakerAccessLevel::Collector)->create();
    $this->property = makeProperty($this->owner, ['details' => ['name' => 'Santos House'], 'units' => ['count' => 2, 'rent_centavos' => 200000]]);
    assignCaretaker($this->property, $this->collector, 'collector');
    $kim = User::factory()->boarder()->create(['name' => 'Kim']);
    Tenancy::factory()->forUnit($this->property->units()->first())->movedInOn('2026-09-01')->create(['tenant_id' => $kim->id]);
    foreach (['2026-10-05' => 200000, '2026-10-12' => 150050] as $day => $amount) {
        Payment::factory()->forPayer($this->property, $kim)->confirmed()->create(['amount_centavos' => $amount, 'paid_on' => $day, 'reference' => '0091']);
    }
});

afterEach(fn () => Date::setTestNow());

/** The signed link for a format, downloaded. */
function reportFile(User $user, string $format): TestResponse
{
    $link = test()->actingAs($user, 'sanctum')->getJson("/api/v1/reports/collections/link?format={$format}&from=2026-10-01&to=2026-10-31")->assertOk();
    expect($link->json('data.filename'))->toBe("dormlink-collections-santos-house-2026-10-01_to_2026-10-31.{$format}");
    auth()->forgetGuards();

    return test()->get($link->json('data.url'))->assertOk();
}

it('previews a report: title, the first rows as people read them, and the total', function () {
    $preview = $this->actingAs($this->collector, 'sanctum')
        ->getJson('/api/v1/reports/collections/preview?from=2026-10-01&to=2026-10-31')->assertOk()->json('data');

    expect($preview['title'])->toBe('Collections')
        ->and($preview['subtitle'])->toBe('Santos House · Oct 1, 2026 – Oct 31, 2026')
        ->and($preview['headers'][7])->toBe('Amount (PHP)')
        ->and($preview['money_columns'])->toBe([7])
        ->and($preview['total'])->toBe(2)
        ->and(array_column($preview['rows'], 7))->toBe(['2,000.00', '1,500.50']);

    // The same rules as a download link.
    $this->actingAs($this->collector, 'sanctum')->getJson('/api/v1/reports/deposits/preview')->assertUnprocessable();
});

it('downloads a report as a PDF', function () {
    $response = reportFile($this->owner, 'pdf');

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(substr($response->getContent(), 0, 4))->toBe('%PDF');
});

it('downloads a report as Excel with numbers, dates and text kept apart', function () {
    $body = reportFile($this->owner, 'xlsx')->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')->streamedContent();
    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($path, $body);

    $reader = new Reader;
    $reader->open($path);
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
    }
    $reader->close();
    unlink($path);

    expect($rows[0][7])->toBe('Amount (PHP)')
        ->and($rows[1][7])->toEqual(2000)                                  // money is a number
        ->and($rows[2][7])->toBe(1500.5)
        ->and($rows[1][0])->toBeInstanceOf(DateTimeImmutable::class)       // a real date
        ->and($rows[1][0]->format('Y-m-d'))->toBe('2026-10-05')
        ->and($rows[1][6])->toBe('0091');                                  // a reference keeps its zeros
})->skip(! extension_loaded('zip'), 'Needs PHP zip (turn it on in Laragon: PHP > Extensions > zip)');

it('gives the admin exports the same formats and a preview', function () {
    Date::setTestNow(CarbonImmutable::parse('2026-11-08 10:00', 'Asia/Manila'));
    $admin = User::factory()->admin()->create();

    $preview = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/exports/owners/preview')->assertOk()->json('data');
    expect($preview['title'])->toBe('Owners')
        ->and($preview['total'])->toBeGreaterThanOrEqual(1);
    $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/admin/exports/owners/preview')->assertForbidden();

    $link = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/exports/owners/link?format=pdf')->assertOk();
    expect($link->json('data.filename'))->toBe('dormlink-admin-owners-2026-11-08.pdf');
    auth()->forgetGuards();
    expect(substr($this->get($link->json('data.url'))->assertOk()->getContent(), 0, 4))->toBe('%PDF');

    $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/exports/owners/link?format=doc')->assertUnprocessable();
});
