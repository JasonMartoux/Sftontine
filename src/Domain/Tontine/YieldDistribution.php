<?php

declare(strict_types=1);

namespace App\Domain\Tontine;

use BcMath\Number;
use Money\Currency;
use Money\Money;

/**
 * Off-chain estimate of the group's yield and its pro-rata split across members, weighted by
 * time × amount. Weights and shares are keyed by spl_object_id(Membership) rather than the
 * membership's database id, so this works uniformly whether or not the aggregate has been
 * persisted yet (Doctrine only assigns ids on flush).
 */
final readonly class YieldDistribution
{
    private const int SECONDS_PER_YEAR = 31_536_000;
    private const int BASIS_POINTS_DIVISOR = 10_000;

    /**
     * @param list<Contribution> $contributions
     */
    public function estimateAccruedYield(array $contributions, int $aprBasisPoints, \DateTimeImmutable $now, \DateTimeImmutable $cap): Money
    {
        $total = new Money('0', new Currency('USDC'));

        foreach ($contributions as $contribution) {
            \assert(is_numeric($contribution->amountMinorUnits));

            $activeSeconds = $this->activeSeconds($contribution->contributedAt, $now, $cap);
            $yield = (new Number($contribution->amountMinorUnits))
                ->mul((string) $aprBasisPoints)
                ->mul((string) $activeSeconds)
                ->div((string) (self::BASIS_POINTS_DIVISOR * self::SECONDS_PER_YEAR), 0);

            $total = $total->add(new Money((string) $yield, new Currency('USDC')));
        }

        return $total;
    }

    /**
     * @param list<Contribution> $contributions
     *
     * @return array<int, Number> keyed by spl_object_id(Membership)
     */
    public function weights(array $contributions, \DateTimeImmutable $now, \DateTimeImmutable $cap): array
    {
        $weights = [];

        foreach ($contributions as $contribution) {
            \assert(is_numeric($contribution->amountMinorUnits));

            $key = spl_object_id($contribution->membership);
            $activeSeconds = $this->activeSeconds($contribution->contributedAt, $now, $cap);
            $weight = (new Number($contribution->amountMinorUnits))->mul((string) $activeSeconds);

            $weights[$key] = ($weights[$key] ?? new Number('0'))->add($weight);
        }

        return $weights;
    }

    /**
     * Largest-remainder allocation: floor each share, then hand out the leftover minor units
     * (at most one per weight) by descending fractional remainder, so the shares always sum
     * exactly to $totalYield. `Money::allocate()` is not used because weights can exceed the
     * int64 range `Money::allocate()`'s ratios rely on.
     *
     * @param array<int, Number> $weights keyed by spl_object_id(Membership)
     *
     * @return array<int, Money> keyed by spl_object_id(Membership)
     */
    public function split(Money $totalYield, array $weights): array
    {
        $totalWeight = new Number('0');
        foreach ($weights as $weight) {
            $totalWeight = $totalWeight->add($weight);
        }

        $shares = [];
        if ('0' === (string) $totalWeight) {
            foreach (array_keys($weights) as $key) {
                $shares[$key] = new Money('0', $totalYield->getCurrency());
            }

            return $shares;
        }

        $totalMinorUnits = new Number($totalYield->getAmount());
        $remainders = [];
        $allocated = new Number('0');

        foreach ($weights as $key => $weight) {
            $share = $totalMinorUnits->mul((string) $weight)->div((string) $totalWeight, 0);
            $shares[$key] = $share;
            $allocated = $allocated->add($share);

            // Fractional remainder scaled by totalWeight, used only to rank keys (larger remainder = distributed first).
            $remainders[$key] = $totalMinorUnits->mul((string) $weight)->sub($share->mul((string) $totalWeight));
        }

        $leftover = (int) (string) $totalMinorUnits->sub((string) $allocated);

        arsort($remainders);
        $keysByRemainder = array_keys($remainders);
        for ($i = 0; $i < $leftover; ++$i) {
            $key = $keysByRemainder[$i % \count($keysByRemainder)];
            $shares[$key] = $shares[$key]->add('1');
        }

        $result = [];
        foreach ($shares as $key => $share) {
            $result[$key] = new Money((string) $share, $totalYield->getCurrency());
        }

        return $result;
    }

    private function activeSeconds(\DateTimeImmutable $contributedAt, \DateTimeImmutable $now, \DateTimeImmutable $cap): int
    {
        $effectiveNow = min($now, $cap);
        if ($effectiveNow <= $contributedAt) {
            return 0;
        }

        return $effectiveNow->getTimestamp() - $contributedAt->getTimestamp();
    }
}
