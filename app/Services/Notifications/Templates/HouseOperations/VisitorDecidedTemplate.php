<?php

namespace App\Services\Notifications\Templates\HouseOperations;

/** To the tenant: an overnight stay was approved or declined (`what`) (D4). */
class VisitorDecidedTemplate extends HouseOpsTemplate
{
    protected function title(array $data): string
    {
        return $data['what'] === 'approved' ? "Overnight stay approved: {$data['visitor_name']}" : "Overnight stay declined: {$data['visitor_name']}";
    }

    protected function sentence(array $data): string
    {
        return $data['what'] === 'approved'
            ? "{$data['visitor_name']} can stay overnight from {$data['night']} ({$data['property_name']})."
            : "{$data['visitor_name']}'s overnight stay from {$data['night']} was declined: {$data['reason']}";
    }
}
