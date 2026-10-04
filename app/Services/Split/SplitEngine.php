<?php

namespace App\Services\Split;

use App\Enums\SplitMethod;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Split module (Billing guide S6): one function divides a total among people,
 * exactly to the centavo. share = total × weight ÷ sum of weights.
 *
 * Rounding (SC-13): every share is rounded DOWN to the centavo, then the
 * leftover centavos go one at a time to the largest fractional remainders
 * (ties: the earlier person in the list), so shares always add up to the total.
 *
 * Everything is integer arithmetic. Weights are decimal strings ("0.5") or
 * integers, scaled by 10,000, so 0.5 is exact and no float is ever used.
 * People are identified by any array key (tenancy id, user id, name...).
 */
final class SplitEngine
{
    private const SCALE = 10_000;

    /** @param  list<int|string>  $people */
    public function equal(int $totalCentavos, array $people): SplitResult
    {
        return $this->byScaledWeights(SplitMethod::Equal, $totalCentavos, array_fill_keys($people, self::SCALE));
    }

    /** @param  array<int|string, int|string>  $weights  person => weight, e.g. ['A' => 1, 'C' => '0.5'] */
    public function weights(int $totalCentavos, array $weights): SplitResult
    {
        return $this->byScaledWeights(SplitMethod::Weights, $totalCentavos, $this->scaleAll($weights));
    }

    /**
     * Fair share by dates (SC-06): weight = days present within the bill's
     * coverage dates, both ends inclusive. A stay with no end runs to the end
     * of the coverage.
     *
     * @param  array<int|string, array{0: DateTimeInterface|string, 1: DateTimeInterface|string|null}>  $stays  person => [from, to]
     */
    public function occupantDays(int $totalCentavos, DateTimeInterface|string $coverageStart, DateTimeInterface|string $coverageEnd, array $stays): SplitResult
    {
        $weights = array_map(
            fn (array $stay) => self::daysPresent($coverageStart, $coverageEnd, $stay[0], $stay[1] ?? null) * self::SCALE,
            $stays,
        );

        return $this->byScaledWeights(SplitMethod::OccupantDays, $totalCentavos, $weights);
    }

    /**
     * Every amount typed in (SC-12 "Fixed amounts"). Nothing is adjusted: a
     * difference from the total shows as unallocated.
     *
     * @param  array<int|string, int>  $amounts  person => centavos
     */
    public function custom(int $totalCentavos, array $amounts): SplitResult
    {
        foreach ($amounts as $amount) {
            $this->assertNotNegative($amount, 'amount');
        }

        return new SplitResult(SplitMethod::Custom, $totalCentavos, $amounts);
    }

    /**
     * Some pay a flat amount, the rest split what is left by weight (SC-12).
     * If the flat amounts exceed the total, the rest pay nothing and the
     * overrun shows as a negative unallocated amount.
     *
     * @param  array<int|string, int>  $fixed  person => centavos
     * @param  array<int|string, int|string>  $rest  person => weight
     */
    public function fixedPlusRemainder(int $totalCentavos, array $fixed, array $rest): SplitResult
    {
        if (array_intersect_key($fixed, $rest) !== []) {
            throw new InvalidArgumentException('A person cannot be both fixed and in the remainder.');
        }
        foreach ($fixed as $amount) {
            $this->assertNotNegative($amount, 'fixed amount');
        }

        $remaining = $totalCentavos - array_sum($fixed);
        $restShares = $remaining > 0
            ? $this->allocate($remaining, $this->scaleAll($rest))
            : array_fill_keys(array_keys($rest), 0);

        return new SplitResult(SplitMethod::FixedPlusRemainder, $totalCentavos, $fixed + $restShares);
    }

    /**
     * SC-07 "Landlord absorbs": everyone's full share is total ÷ headcount, a
     * person pays their weight of it (0.5 → half), and the landlord covers
     * what is left (the unallocated amount).
     *
     * @param  array<int|string, int|string>  $weights  person => weight (1 = a full share)
     */
    public function weightedAbsorb(int $totalCentavos, array $weights): SplitResult
    {
        $scaled = $this->scaleAll($weights);
        if ($scaled === []) {
            return new SplitResult(SplitMethod::WeightedAbsorb, $totalCentavos, []);
        }

        // Amount the people pay together: total × (sum of weights ÷ headcount).
        $collected = intdiv($totalCentavos * array_sum($scaled), count($scaled) * self::SCALE);

        return new SplitResult(SplitMethod::WeightedAbsorb, $totalCentavos, $this->allocate($collected, $scaled));
    }

    /** Inclusive days a stay overlaps the coverage dates (0 if none). */
    public static function daysPresent(
        DateTimeInterface|string $coverageStart,
        DateTimeInterface|string $coverageEnd,
        DateTimeInterface|string $from,
        DateTimeInterface|string|null $to,
    ): int {
        $start = max(self::day($coverageStart), self::day($from));
        $end = min(self::day($coverageEnd), $to === null ? self::day($coverageEnd) : self::day($to));

        return $end->lessThan($start) ? 0 : (int) $start->diffInDays($end) + 1;
    }

    /** @param  array<int|string, int>  $scaledWeights */
    private function byScaledWeights(SplitMethod $method, int $total, array $scaledWeights): SplitResult
    {
        return new SplitResult($method, $total, $this->allocate($total, $scaledWeights));
    }

    /**
     * Largest-remainder allocation of $total by integer weights.
     *
     * @param  array<int|string, int>  $weights
     * @return array<int|string, int>
     */
    private function allocate(int $total, array $weights): array
    {
        $sum = array_sum($weights);
        if ($weights === [] || $sum === 0) {
            return array_map(fn () => 0, $weights);
        }

        // Negative totals (credits) split the same way, then flip sign.
        $sign = $total < 0 ? -1 : 1;
        $total = abs($total);

        $shares = [];
        $remainders = [];
        $order = 0;
        foreach ($weights as $person => $weight) {
            $numerator = $total * $weight;
            $shares[$person] = intdiv($numerator, $sum);
            $remainders[] = ['person' => $person, 'remainder' => $numerator % $sum, 'order' => $order++];
        }

        $leftover = $total - array_sum($shares);
        usort($remainders, fn ($a, $b) => [$b['remainder'], $a['order']] <=> [$a['remainder'], $b['order']]);
        for ($i = 0; $i < $leftover; $i++) {
            $shares[$remainders[$i]['person']]++;
        }

        return $sign === 1 ? $shares : array_map(fn (int $s) => -$s, $shares);
    }

    /**
     * @param  array<int|string, int|string>  $weights
     * @return array<int|string, int>
     */
    private function scaleAll(array $weights): array
    {
        return array_map(fn ($w) => $this->scale($w), $weights);
    }

    /** "0.5" → 5000, 30 → 300000. Up to 4 decimal places; no negatives. */
    private function scale(int|string $weight): int
    {
        if (is_int($weight)) {
            $this->assertNotNegative($weight, 'weight');

            return $weight * self::SCALE;
        }

        if (! preg_match('/^(\d+)(?:\.(\d{1,4}))?$/', trim($weight), $m)) {
            throw new InvalidArgumentException("Invalid weight: {$weight}");
        }

        return (int) $m[1] * self::SCALE + (int) str_pad($m[2] ?? '0', 4, '0');
    }

    private function assertNotNegative(int $value, string $what): void
    {
        if ($value < 0) {
            throw new InvalidArgumentException("A {$what} cannot be negative.");
        }
    }

    private static function day(DateTimeInterface|string $value): CarbonImmutable
    {
        return ($value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)->setTimezone('Asia/Manila')
            : CarbonImmutable::parse($value, 'Asia/Manila'))->startOfDay();
    }
}
