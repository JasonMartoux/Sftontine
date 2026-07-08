<?php

declare(strict_types=1);

namespace App\Application\Tontine\UseCase;

use App\Application\Tontine\Dto\GroupPotView;
use App\Application\Tontine\Dto\MemberPotView;
use App\Application\Tontine\Port\TontineGroupRepositoryInterface;
use App\Application\Vault\Port\YieldSnapshotRepositoryInterface;
use App\Domain\Tontine\ContributionSchedule;
use App\Domain\Tontine\Membership;
use App\Domain\Tontine\Periodicity;
use App\Domain\Tontine\SavingsCycle;
use App\Domain\Tontine\YieldDistribution;
use BcMath\Number;
use Money\Currency;
use Money\Money;
use Psr\Clock\ClockInterface;

final readonly class GroupPotReader
{
    private const int SECONDS_PER_YEAR = 31_536_000;
    private const int BASIS_POINTS_DIVISOR = 10_000;

    private ContributionSchedule $schedule;
    private YieldDistribution $yieldDistribution;

    public function __construct(
        private TontineGroupRepositoryInterface $groups,
        private YieldSnapshotRepositoryInterface $snapshots,
        private ClockInterface $clock,
    ) {
        $this->schedule = new ContributionSchedule();
        $this->yieldDistribution = new YieldDistribution();
    }

    public function read(int $groupId): ?GroupPotView
    {
        $group = $this->groups->find($groupId);
        if (null === $group) {
            return null;
        }

        $now = $this->clock->now();
        $cycle = $group->currentCycle();
        $cap = $cycle->endsAt ?? $now;
        $aprBasisPoints = $this->snapshots->findMostRecent()->aprBasisPoints ?? 0;

        $contributions = $group->contributions();
        $estimatedYield = $this->yieldDistribution->estimateAccruedYield($contributions, $aprBasisPoints, $now, $cap);
        $weights = $this->yieldDistribution->weights($contributions, $now, $cap);
        $shares = $this->yieldDistribution->split($estimatedYield, $weights);

        $potTotal = $group->potTotal();

        $members = [];
        foreach ($group->memberships() as $membership) {
            $members[] = $this->memberView($membership, $cycle, $group->periodicity, $group->installmentsPerCycle, $now, $shares);
        }

        return new GroupPotView(
            groupId: $groupId,
            name: $group->name,
            periodicity: $group->periodicity->value,
            potTotalDisplay: self::display($potTotal),
            estimatedYieldDisplay: self::display($estimatedYield),
            flowRatePerSecondDisplay: self::flowRatePerSecondDisplay($potTotal, $aprBasisPoints),
            computedAtTimestamp: $now->getTimestamp(),
            aprBasisPoints: $aprBasisPoints,
            cycleNumber: $cycle?->number,
            cycleEndsAt: $cycle?->endsAt,
            members: $members,
        );
    }

    /**
     * @param array<int, Money> $sharesByMembershipId keyed by spl_object_id(Membership)
     */
    private function memberView(Membership $membership, ?SavingsCycle $cycle, Periodicity $periodicity, int $installmentsPerCycle, \DateTimeImmutable $now, array $sharesByMembershipId): MemberPotView
    {
        $paidCount = 0;
        $contributedTotal = new Money('0', new Currency('USDC'));
        foreach ($membership->group->contributions() as $contribution) {
            if ($contribution->membership === $membership) {
                ++$paidCount;
                $contributedTotal = $contributedTotal->add($contribution->amount);
            }
        }

        $expected = null === $cycle ? 0 : $this->schedule->expectedInstallmentsFor($membership, $cycle, $periodicity, $installmentsPerCycle, $now);
        $missed = max(0, $expected - $paidCount);
        $nextDueDate = null === $cycle ? null : $this->schedule->nextDueDateFor($membership, $cycle, $periodicity, $installmentsPerCycle, $now);

        $share = $sharesByMembershipId[spl_object_id($membership)] ?? new Money('0', new Currency('USDC'));

        return new MemberPotView(
            userId: $membership->user->id,
            email: $membership->user->email,
            walletAddress: $membership->user->walletAddress->value,
            role: $membership->role->value,
            contributedDisplay: self::display($contributedTotal),
            yieldShareDisplay: self::display($share),
            expectedInstallments: $expected,
            paidInstallments: $paidCount,
            missedInstallments: $missed,
            isLate: $missed > 0,
            nextDueDate: $nextDueDate,
        );
    }

    private static function display(Money $amount): string
    {
        $minorUnits = $amount->getAmount();
        \assert(is_numeric($minorUnits));

        return (string) (new Number($minorUnits))->div('1000000', 6);
    }

    private static function flowRatePerSecondDisplay(Money $potTotal, int $aprBasisPoints): string
    {
        $minorUnits = $potTotal->getAmount();
        \assert(is_numeric($minorUnits));

        $flowRate = (new Number($minorUnits))
            ->mul((string) $aprBasisPoints)
            ->div((string) (self::BASIS_POINTS_DIVISOR * self::SECONDS_PER_YEAR), 6);

        return (string) $flowRate->div('1000000', 6);
    }
}
