<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Date rules from the Technical standards: every date is Asia/Manila, and
 * end dates (move-out, notices) last until 23:59:59 of that day.
 */
final class ManilaDate
{
    public const TIMEZONE = 'Asia/Manila';

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE);
    }

    public static function today(): CarbonImmutable
    {
        return self::now()->startOfDay();
    }

    /** Parse a date or datetime as Manila time. */
    public static function parse(string|DateTimeInterface $value): CarbonImmutable
    {
        return $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)->setTimezone(self::TIMEZONE)
            : CarbonImmutable::parse($value, self::TIMEZONE);
    }

    /** The last moment an end date still counts: that day at 23:59:59 Manila. */
    public static function endOfDay(string|DateTimeInterface $date): CarbonImmutable
    {
        return self::parse($date)->setTime(23, 59, 59);
    }

    /** Whether an end date has fully passed (the day after it has started). */
    public static function hasEnded(string|DateTimeInterface $endDate, ?DateTimeInterface $at = null): bool
    {
        $at = $at ? self::parse($at) : self::now();

        return $at->greaterThan(self::endOfDay($endDate));
    }
}
