<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money helpers. All amounts are integer centavos (bigint columns named
 * `*_centavos`); floats are never used for money.
 */
final class Money
{
    /**
     * Parse a peso amount typed by a user ("1,234.5", "₱600", "600") into centavos.
     * Rejects more than two decimal places instead of silently rounding.
     */
    public static function toCentavos(string|int $pesos): int
    {
        if (is_int($pesos)) {
            return $pesos * 100;
        }

        $clean = str_replace([',', '₱', ' '], '', trim($pesos));

        if (! preg_match('/^(-)?(\d+)(?:\.(\d{1,2}))?$/', $clean, $m)) {
            throw new InvalidArgumentException("Invalid peso amount: {$pesos}");
        }

        $centavos = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '0', 2, '0');

        return $m[1] === '-' ? -$centavos : $centavos;
    }

    /**
     * amount × numerator ÷ denominator, rounded half up to the centavo, in
     * integers only (e.g. proration: rent × 10 days ÷ 30 → 133333).
     */
    public static function fraction(int $centavos, int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException('Denominator must be positive.');
        }

        $sign = ($centavos * $numerator) < 0 ? -1 : 1;
        $product = abs($centavos * $numerator);

        return $sign * intdiv($product * 2 + $denominator, $denominator * 2);
    }

    /** Format centavos for display, e.g. 133333 → "₱1,333.33". */
    public static function format(int $centavos): string
    {
        $sign = $centavos < 0 ? '-' : '';
        $abs = abs($centavos);

        return $sign.'₱'.number_format(intdiv($abs, 100)).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
