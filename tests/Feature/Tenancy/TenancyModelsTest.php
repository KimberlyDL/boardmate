<?php

use App\Enums\ApplicationStatus;
use App\Models\RoomLeader;
use App\Models\Tenancy;
use App\Models\TenancyDiscount;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->property = makeProperty($this->owner);
    $this->room = $this->property->rooms()->first();
    $this->units = $this->room->units;
});

it('allows only one current tenancy per unit', function () {
    Tenancy::factory()->forUnit($this->units[0])->create();

    expect(fn () => Tenancy::factory()->forUnit($this->units[0])->create())->toThrow(QueryException::class);
});

it('allows only one current tenancy per tenant', function () {
    $tenant = User::factory()->create();
    Tenancy::factory()->forUnit($this->units[0])->create(['tenant_id' => $tenant->id]);

    expect(fn () => Tenancy::factory()->forUnit($this->units[1])->create(['tenant_id' => $tenant->id]))
        ->toThrow(QueryException::class);
});

it('frees the unit and the tenant once a tenancy has ended', function () {
    $tenant = User::factory()->create();
    $first = Tenancy::factory()->forUnit($this->units[0])->create(['tenant_id' => $tenant->id]);
    DB::table('tenancies')->where('id', $first->id)->update(['status' => 'ended']);

    $second = Tenancy::factory()->forUnit($this->units[0])->create(['tenant_id' => $tenant->id]);

    expect($second->exists)->toBeTrue()
        ->and($this->units[0]->currentTenancy->is($second))->toBeTrue()
        ->and(Tenancy::current()->count())->toBe(1);
});

it('keeps one current leader per room and the history of past ones', function () {
    $first = RoomLeader::factory()->create(['room_id' => $this->room->id]);

    // A savepoint, so the expected failure does not abort the test's transaction.
    expect(fn () => DB::transaction(fn () => RoomLeader::factory()->create(['room_id' => $this->room->id])))
        ->toThrow(QueryException::class);

    $first->update(['ended_on' => now('Asia/Manila')->toDateString(), 'ended_reason' => 'Moved out']);
    $second = RoomLeader::factory()->create(['room_id' => $this->room->id]);

    expect($this->room->leader->is($second))->toBeTrue()
        ->and($this->room->leaders)->toHaveCount(2);
});

it('takes a fixed or percent discount off the rent, never more than the rent', function () {
    $fixed = new TenancyDiscount(['kind' => 'fixed', 'value' => 50000]);
    $percent = new TenancyDiscount(['kind' => 'percent', 'value' => 1000]); // 10%
    $huge = new TenancyDiscount(['kind' => 'fixed', 'value' => 900000]);

    expect($fixed->amountOff(450000))->toBe(50000)
        ->and(450000 - $fixed->amountOff(450000))->toBe(400000)
        ->and($percent->amountOff(450000))->toBe(45000)
        ->and($huge->amountOff(450000))->toBe(450000);
});

it('finds the discount in force on a day', function () {
    $tenancy = Tenancy::factory()->forUnit($this->units[0])->create();
    TenancyDiscount::factory()->create(['tenancy_id' => $tenancy->id, 'effective_from' => '2026-10-01', 'effective_to' => '2026-10-31']);
    TenancyDiscount::factory()->percent(500)->create(['tenancy_id' => $tenancy->id, 'effective_from' => '2026-11-01']);

    expect($tenancy->discounts()->inForceOn('2026-10-15')->first()->value)->toBe(50000)
        ->and($tenancy->discounts()->inForceOn('2026-11-01')->first()->value)->toBe(500)
        ->and($tenancy->discounts()->inForceOn('2026-09-30')->exists())->toBeFalse();
});

it('does not count a moved-in application as open', function () {
    expect(ApplicationStatus::MovedIn->isOpen())->toBeFalse()
        ->and(ApplicationStatus::openValues())->not->toContain('moved_in');
});
