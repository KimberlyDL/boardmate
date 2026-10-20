<?php

namespace App\Services\Notifications\Templates\HouseOperations;

/** To the tenant: their late-entry request was approved or declined (`what`) (D2). */
class LateEntryDecidedTemplate extends HouseOpsTemplate
{
    protected function title(array $data): string
    {
        return $data['what'] === 'approved' ? "Late entry approved: {$data['night']}" : "Late entry declined: {$data['night']}";
    }

    protected function sentence(array $data): string
    {
        return $data['what'] === 'approved'
            ? "You can come back by {$data['arrival']} on {$data['night']} ({$data['property_name']})."
            : "Your request to come back late on {$data['night']} was declined: {$data['reason']}";
    }
}
