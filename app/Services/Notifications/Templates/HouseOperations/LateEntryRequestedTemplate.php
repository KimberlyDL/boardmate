<?php

namespace App\Services\Notifications\Templates\HouseOperations;

/** To the owner and caretakers: a tenant asks to come back after curfew (D2). */
class LateEntryRequestedTemplate extends HouseOpsTemplate
{
    protected function title(array $data): string
    {
        return "Late entry: {$data['tenant_name']}, {$data['night']}";
    }

    protected function sentence(array $data): string
    {
        return "{$data['tenant_name']} (room {$data['room_label']}, {$data['property_name']}) asks to come back by {$data['arrival']} on {$data['night']}: {$data['reason']}";
    }
}
