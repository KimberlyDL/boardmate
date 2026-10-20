<?php

namespace Database\Factories;

use App\Enums\VisitorStatus;
use App\Models\Tenancy;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pass the stay with forTenancy().
 *
 * @extends Factory<Visitor>
 */
class VisitorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Ana Cruz',
            'visit_on' => '2026-10-20',
            'status' => VisitorStatus::Registered,
        ];
    }

    public function forTenancy(Tenancy $tenancy): static
    {
        return $this->state(fn () => ['property_id' => $tenancy->property_id, 'tenancy_id' => $tenancy->id, 'registered_by' => $tenancy->tenant_id]);
    }

    public function overnight(int $nights = 1, VisitorStatus $status = VisitorStatus::Approved): static
    {
        return $this->state(fn () => ['overnight' => true, 'nights' => $nights, 'status' => $status]);
    }
}
