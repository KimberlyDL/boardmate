<?php

use App\Authorization\PropertyAccess;
use App\Enums\CaretakerAccessLevel as Level;
use App\Enums\PropertyAbility as A;

/*
| The matrix from the Billing guide, "Caretaker access levels".
*/

it('lets the owner do everything', function (A $ability) {
    expect(PropertyAccess::allows(null, $ability))->toBeTrue();
})->with(A::cases());

it('lets a collector do collection work only', function () {
    foreach ([A::RecordPayments, A::ConfirmPaymentProofs, A::SendReminders, A::MarkLate, A::ApproveMoveOut, A::ViewArrears] as $ability) {
        expect(PropertyAccess::allows(Level::Collector, $ability))->toBeTrue("collector should {$ability->value}");
    }

    // Collector "Cannot do": change prices or rules, edit issued bills, refund deposits.
    foreach ([A::ManagePrices, A::ManageRules, A::EditIssuedBills, A::RefundDeposits, A::ApproveBookings, A::ViewCollections] as $ability) {
        expect(PropertyAccess::allows(Level::Collector, $ability))->toBeFalse("collector should not {$ability->value}");
    }
});

it('lets a manager do what the owner can, except the owner-only actions', function () {
    foreach ([A::ManagePrices, A::ManageRules, A::ManageUtilityBills, A::ManageSettlements, A::RefundDeposits, A::ApproveBookings, A::RecordPayments] as $ability) {
        expect(PropertyAccess::allows(Level::Manager, $ability))->toBeTrue("manager should {$ability->value}");
    }

    // Manager "Cannot do": add or remove caretakers, change the owner's payment
    // details, delete a property, transfer ownership.
    foreach ([A::ManageCaretakers, A::EditOwnerPaymentDetails, A::DeleteProperty, A::TransferOwnership] as $ability) {
        expect(PropertyAccess::allows(Level::Manager, $ability))->toBeFalse("manager should not {$ability->value}");
    }
});

it('gives each level a strictly larger set than the one below', function () {
    $collector = PropertyAccess::abilitiesFor(Level::Collector);
    $manager = PropertyAccess::abilitiesFor(Level::Manager);
    $owner = PropertyAccess::abilitiesFor(null);

    expect(array_diff(array_column($collector, 'value'), array_column($manager, 'value')))->toBe([])
        ->and(count($manager))->toBeGreaterThan(count($collector))
        ->and(count($owner))->toBe(count(A::cases()))
        ->and(count($owner))->toBeGreaterThan(count($manager));
});
