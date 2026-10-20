<?php

namespace Database\Factories;

use App\Enums\MaintenanceCategory;
use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceUrgency;
use App\Models\MaintenanceRequest;
use App\Models\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pass the stay with forTenancy() (property, room and reporter follow).
 *
 * @extends Factory<MaintenanceRequest>
 */
class MaintenanceRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category' => MaintenanceCategory::Plumbing,
            'description' => 'The sink is leaking.',
            'urgency' => MaintenanceUrgency::Normal,
            'status' => MaintenanceStatus::Submitted,
        ];
    }

    public function forTenancy(Tenancy $tenancy): static
    {
        return $this->state(fn () => [
            'property_id' => $tenancy->property_id,
            'room_id' => $tenancy->room_id,
            'tenancy_id' => $tenancy->id,
            'reported_by' => $tenancy->tenant_id,
        ]);
    }

    public function status(MaintenanceStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
