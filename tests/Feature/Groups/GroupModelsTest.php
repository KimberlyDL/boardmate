<?php

use App\Enums\FilePurpose;
use App\Enums\GroupExpenseKind;
use App\Enums\GroupRole;
use App\Enums\RepaymentStatus;
use App\Enums\SplitMethod;
use App\Models\Bill;
use App\Models\ContributionGroup;
use App\Models\ExpenseShare;
use App\Models\GroupExpense;
use App\Models\GroupMember;
use App\Models\GroupRepayment;
use App\Models\Tenancy;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->property = makeProperty($this->owner);
    $this->room = $this->property->rooms()->first();
    $this->group = ContributionGroup::factory()->forRoom($this->room)->create();
    $this->kim = GroupMember::factory()->forGroup($this->group)->admin()->create(['name' => 'Kim', 'user_id' => User::factory()->boarder()->create()->id]);
    $this->ben = GroupMember::factory()->forGroup($this->group)->create(['name' => 'Ben', 'joined_on' => '2026-09-25']);
});

it('keeps one group per room, with its members, roles and former members', function () {
    GroupMember::factory()->forGroup($this->group)->create(['name' => 'Leo', 'left_on' => '2026-09-30']);

    expect($this->room->contributionGroup->id)->toBe($this->group->id)
        ->and($this->group->default_split_method)->toBe(SplitMethod::OccupantDays)
        ->and($this->group->members->pluck('name')->all())->toBe(['Kim', 'Leo', 'Ben'])
        ->and($this->group->currentMembers->pluck('name')->all())->toBe(['Kim', 'Ben'])
        ->and($this->kim->role)->toBe(GroupRole::Admin)
        ->and($this->kim->role->canManage())->toBeTrue()
        ->and($this->ben->role->canManage())->toBeFalse();

    expect(fn () => ContributionGroup::factory()->forRoom($this->room)->create())->toThrow(UniqueConstraintViolationException::class);
});

it('posts an owner bill once as a locked line, with each member\'s share owed to the one who paid', function () {
    $tenancy = Tenancy::factory()->forUnit($this->property->units()->first())->create();
    $bill = Bill::factory()->forTenancy($tenancy)->create();
    $line = GroupExpense::factory()->paidBy($this->kim)->create([
        'kind' => GroupExpenseKind::OwnerBill, 'bill_id' => $bill->id, 'description' => 'Meralco, Sep 10 – Oct 9', 'amount_centavos' => 77143, 'split_method' => SplitMethod::OccupantDays,
    ]);
    ExpenseShare::create(['expense_id' => $line->id, 'member_id' => $this->kim->id, 'amount_centavos' => 51429]);
    ExpenseShare::create(['expense_id' => $line->id, 'member_id' => $this->ben->id, 'amount_centavos' => 25714]);

    $line->refresh();
    expect($line->isLocked())->toBeTrue()
        ->and($line->paidBy->name)->toBe('Kim')
        ->and((int) $line->shares()->sum('amount_centavos'))->toBe(77143)
        ->and($this->ben->shares()->sole()->expense->id)->toBe($line->id);

    expect(fn () => GroupExpense::factory()->paidBy($this->kim)->create(['kind' => GroupExpenseKind::OwnerBill, 'bill_id' => $bill->id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

// Its own test: after one duplicate, Postgres refuses everything else in the same transaction.
it('gives each member one share of a line', function () {
    $line = GroupExpense::factory()->paidBy($this->kim)->create();
    ExpenseShare::create(['expense_id' => $line->id, 'member_id' => $this->ben->id, 'amount_centavos' => 75000]);

    expect(fn () => ExpenseShare::create(['expense_id' => $line->id, 'member_id' => $this->ben->id, 'amount_centavos' => 1]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('keeps corrections as adjustments and repayments as two-step records', function () {
    $internet = GroupExpense::factory()->paidBy($this->ben)->create(['description' => 'Internet, October', 'amount_centavos' => 150000]);
    $fix = GroupExpense::factory()->paidBy($this->ben)->create([
        'kind' => GroupExpenseKind::Adjustment, 'adjusts_expense_id' => $internet->id, 'amount_centavos' => -10000, 'reason' => 'Promo discount',
    ]);
    $repayment = GroupRepayment::factory()->between($this->kim, $this->ben)->create(['amount_centavos' => 70000]);

    expect($fix->adjusts->id)->toBe($internet->id)
        ->and($fix->isLocked())->toBeFalse()
        ->and($repayment->status)->toBe(RepaymentStatus::Pending)
        ->and($repayment->from->name)->toBe('Kim')
        ->and($repayment->to->name)->toBe('Ben')
        ->and($this->group->repayments()->count())->toBe(1);
});

it('keeps proofs between members private', function () {
    expect(FilePurpose::GroupProof->isPublic())->toBeFalse()
        ->and(FilePurpose::GroupProof->allowedExtensions())->toContain('pdf');
});
