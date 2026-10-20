<?php

namespace App\Services\Properties;

use App\Enums\HouseRuleCategory;
use App\Models\PropertySettings;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The house-rule lines that come from a property's curfew and visitor
 * settings (D2, D4), so the rules always match what the app enforces.
 */
final class HouseRuleAutoLines
{
    /** @return list<array{category: HouseRuleCategory, body: string}> */
    public static function for(?PropertySettings $settings): array
    {
        $s = $settings ?? new PropertySettings(PropertySettings::DEFAULTS);
        $lines = [];

        if ($s->curfew_time) {
            $lines[] = [HouseRuleCategory::Curfew, 'The curfew is '.self::clock($s->curfew_time).'. If you will be late, ask in advance in the app.'];
        }
        if ($s->visitor_hours_start && $s->visitor_hours_end) {
            $lines[] = [HouseRuleCategory::Visitors, 'Visitors are welcome from '.self::clock($s->visitor_hours_start).' to '.self::clock($s->visitor_hours_end).'.'];
        }
        $nights = (int) $s->overnight_limit_per_month;
        $limit = $nights === 1 ? '1 night' : "{$nights} nights";
        $lines[] = [HouseRuleCategory::Visitors, $s->overnight_requires_approval
            ? "Overnight visitors need approval first, up to {$limit} a month for each boarder."
            : "Overnight visitors: up to {$limit} a month for each boarder. Register them in the app."];
        if ($s->extra_occupant_fee_enabled && $s->extra_occupant_fee_centavos) {
            $lines[] = [HouseRuleCategory::Visitors, 'Beyond that, an extra-occupant fee of '.Money::format((int) $s->extra_occupant_fee_centavos).' may be charged.'];
        }

        return array_map(fn ($l) => ['category' => $l[0], 'body' => $l[1]], $lines);
    }

    /** "22:00" or "22:00:00" → "10:00 PM". */
    private static function clock(string $time): string
    {
        return CarbonImmutable::createFromFormat('H:i', substr($time, 0, 5))->format('g:i A');
    }
}
