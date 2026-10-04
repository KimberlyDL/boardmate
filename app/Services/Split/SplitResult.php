<?php

namespace App\Services\Split;

use App\Enums\SplitMethod;

/**
 * Each person's share of a bill, in centavos, in the order they were given.
 * `unallocated` is total − sum of shares: the gap a manager must absorb or fix
 * before issuing (SC-12), or what the landlord covers (SC-07). Negative means
 * the shares exceed the total.
 */
final readonly class SplitResult
{
    /** @param  array<int|string, int>  $shares */
    public function __construct(
        public SplitMethod $method,
        public int $totalCentavos,
        public array $shares,
    ) {}

    public function allocated(): int
    {
        return array_sum($this->shares);
    }

    public function unallocated(): int
    {
        return $this->totalCentavos - $this->allocated();
    }

    public function isBalanced(): bool
    {
        return $this->unallocated() === 0;
    }

    public function shareOf(int|string $person): int
    {
        return $this->shares[$person] ?? 0;
    }
}
