<?php

namespace Database\Factories;

use App\Enums\LateEntryStatus;
use App\Models\LateEntryRequest;
use App\Models\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pass the stay with forTenancy().
 *
 * @extends Factory<LateEntryRequest>
 */
class LateEntryRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'on_date' => '2026-10-20',
            'expected_arrival' => '23:30',
            'reason' => 'Group project at school.',
            'status' => LateEntryStatus::Pending,
        ];
    }

    public function forTenancy(Tenancy $tenancy): static
    {
        return $this->state(fn () => ['property_id' => $tenancy->property_id, 'tenancy_id' => $tenancy->id, 'requested_by' => $tenancy->tenant_id]);
    }
}
