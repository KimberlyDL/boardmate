<?php

namespace App\Enums;

/** How a boarder accepted a version of the house rules (D3). */
enum HouseRuleAcceptanceMethod: string
{
    case App = 'app';
    /** Staff recorded at move-in that the boarder accepted them in person. */
    case InPerson = 'in_person';

    public function label(): string
    {
        return match ($this) {
            self::App => 'In the app',
            self::InPerson => 'In person',
        };
    }
}
