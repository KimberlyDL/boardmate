<?php

namespace Database\Factories;

use App\Models\CollectionNote;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pass the property and payer with forPayer().
 *
 * @extends Factory<CollectionNote>
 */
class CollectionNoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'note' => 'Visited; will pay on Friday.',
            'promised_on' => null,
        ];
    }

    public function forPayer(Property $property, User $payer): static
    {
        return $this->state(fn () => ['property_id' => $property->id, 'payer_id' => $payer->id]);
    }
}
