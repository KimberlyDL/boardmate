<?php

namespace App\Services\Notifications\Templates\HouseOperations;

/**
 * Overnight nights went over the monthly limit (D4): a reminder to the
 * tenant (`audience` tenant), a notice to the owner (`audience` owner).
 * Never a charge: the owner may add the extra-occupant fee by hand.
 */
class OvernightOverLimitTemplate extends HouseOpsTemplate
{
    protected function title(array $data): string
    {
        return $data['audience'] === 'tenant' ? 'Over the overnight limit' : "Over the overnight limit: {$data['tenant_name']}";
    }

    protected function sentence(array $data): string
    {
        $count = "{$data['used']} nights this month; the limit is {$data['limit']}";

        if ($data['audience'] === 'tenant') {
            return "With {$data['visitor_name']}, your overnight visitors come to {$count} ({$data['property_name']}). Please keep to the house rules.";
        }
        $fee = empty($data['fee']) ? '' : " You may charge the extra-occupant fee ({$data['fee']}) as a one-time charge if it continues.";

        return "{$data['tenant_name']} (room {$data['room_label']}) has had overnight visitors for {$count}.{$fee}";
    }
}
