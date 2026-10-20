<?php

namespace App\Services\Notifications\Templates\HouseOperations;

/** To the tenant: a late arrival without an approved request was recorded (D2). A record only. */
class CurfewViolationLoggedTemplate extends HouseOpsTemplate
{
    protected function title(array $data): string
    {
        return "Late arrival recorded: {$data['night']}";
    }

    protected function sentence(array $data): string
    {
        $arrival = empty($data['arrival']) ? '' : " at {$data['arrival']}";

        return "{$data['property_name']} recorded that you came back after curfew{$arrival} on {$data['night']}. No fee is added. Next time, ask ahead in the app.";
    }
}
