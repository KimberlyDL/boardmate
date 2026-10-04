<?php

use App\Enums\DocumentType;
use App\Models\User;
use App\Services\Numbering\Contracts\NumberingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->numbers = app(NumberingService::class);
    $this->owner = User::factory()->create();
});

it('numbers receipts per owner like OR-2026-000123', function () {
    $at = CarbonImmutable::parse('2026-10-04 10:00', 'Asia/Manila');

    expect($this->numbers->next($this->owner, DocumentType::Receipt, $at))->toBe('OR-2026-000001')
        ->and($this->numbers->next($this->owner, DocumentType::Receipt, $at))->toBe('OR-2026-000002')
        ->and($this->numbers->next($this->owner, DocumentType::Bill, $at))->toBe('BILL-2026-000001')
        ->and($this->numbers->next($this->owner, DocumentType::Notice, $at))->toBe('NTC-2026-000001');
});

it('keeps a separate sequence for each owner', function () {
    $other = User::factory()->create();

    $this->numbers->next($this->owner, DocumentType::Receipt);
    $this->numbers->next($this->owner, DocumentType::Receipt);

    expect($this->numbers->next($other, DocumentType::Receipt))->toEndWith('-000001');
});

it('starts again at 1 each year, using the Manila date', function () {
    $dec31 = CarbonImmutable::parse('2026-12-31 23:30', 'Asia/Manila');
    // 2026-12-31 16:30 UTC is already Jan 1, 2027 in Manila.
    $newYearInManila = CarbonImmutable::parse('2026-12-31 16:30', 'UTC');

    expect($this->numbers->next($this->owner, DocumentType::Receipt, $dec31))->toBe('OR-2026-000001')
        ->and($this->numbers->next($this->owner, DocumentType::Receipt, $newYearInManila))->toBe('OR-2027-000001')
        ->and($this->numbers->next($this->owner, DocumentType::Receipt, $dec31))->toBe('OR-2026-000002');
});

it('never repeats a number over many documents', function () {
    $issued = [];
    for ($i = 0; $i < 200; $i++) {
        $issued[] = $this->numbers->next($this->owner, DocumentType::Receipt);
    }

    expect(array_unique($issued))->toHaveCount(200)
        ->and(end($issued))->toEndWith('-000200');
});

it('does not burn a number when the saving transaction rolls back', function () {
    try {
        DB::transaction(function () {
            $this->numbers->next($this->owner, DocumentType::Receipt);
            throw new RuntimeException('receipt save failed');
        });
    } catch (RuntimeException) {
    }

    expect($this->numbers->next($this->owner, DocumentType::Receipt))->toEndWith('-000001');
});
