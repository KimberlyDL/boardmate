<?php

namespace Database\Factories;

use App\Enums\NoticeStatus;
use App\Models\Tenancy;
use App\Models\TenancyNotice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pass the tenancy with forTenancy().
 *
 * @extends Factory<TenancyNotice>
 */
class TenancyNoticeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'end_on' => '2026-11-25',
            'status' => NoticeStatus::Pending,
            'is_short_notice' => false,
        ];
    }

    public function forTenancy(Tenancy $tenancy): static
    {
        return $this->state(fn () => ['tenancy_id' => $tenancy->id, 'filed_by' => $tenancy->tenant_id]);
    }
}
