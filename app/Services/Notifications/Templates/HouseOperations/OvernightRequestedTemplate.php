<?php

namespace App\Services\Notifications\Templates\HouseOperations;

/** To the owner and caretakers: an overnight visitor waits for approval (D4). */
class OvernightRequestedTemplate extends HouseOpsTemplate
{
    protected function title(array $data): string
    {
        return "Overnight visitor: {$data['visitor_name']}";
    }

    protected function sentence(array $data): string
    {
        $nights = $data['nights'] === 1 ? '1 night' : "{$data['nights']} nights";
        $over = ($data['over_limit'] ?? false) ? ' This goes over the monthly limit.' : '';

        return "{$data['tenant_name']} (room {$data['room_label']}, {$data['property_name']}) asks to have {$data['visitor_name']} stay {$nights} from {$data['night']}.{$over}";
    }
}
