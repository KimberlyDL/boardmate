<?php

use App\Enums\RentalMode;
use App\Models\Property;
use App\Models\PropertyCaretaker;
use App\Models\User;
use App\Services\Properties\PropertySetup;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| Feature tests boot Laravel against the separate `boardmate_test` Postgres
| database (phpunit.xml) and reset it per test. Mail is the `array` mailer, so
| no real email is ever sent from tests.
|
| Unit tests are pure PHP (money, dates, split engine) and do not boot Laravel.
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * A property set up the real way (PropertySetup): defaults, units and rents.
 * Bedspace mode by default with 3 beds at ₱2,000.
 */
function makeProperty(User $owner, array $overrides = []): Property
{
    $mode = RentalMode::from($overrides['rental_mode'] ?? 'bedspaces');
    $units = $overrides['units'] ?? ($mode === RentalMode::Whole
        ? ['capacity' => 4, 'rent_centavos' => 1200000]
        : ['count' => 3, 'rent_centavos' => 200000]);

    return app(PropertySetup::class)->create(
        $owner,
        array_merge(['name' => 'Santos Boarding House', 'city' => 'Manila'], $overrides['details'] ?? []),
        $mode,
        $units,
        $overrides['type'] ?? 'boarding_house',
    );
}

/** Assign an (owner-linked) caretaker to a property at a level. */
function assignCaretaker(Property $property, User $caretaker, string $level): void
{
    PropertyCaretaker::updateOrCreate(
        ['property_id' => $property->id, 'caretaker_id' => $caretaker->id],
        ['access_level' => $level],
    );
}

/*
| Concurrency tests run real parallel processes, which only see committed
| data, so they truncate tables instead of rolling back a transaction.
*/
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Concurrency');
