<?php

declare(strict_types=1);

namespace App\Domain\Tontine;

final readonly class ContributionSchedule
{
    /**
     * @return list<\DateTimeImmutable>
     */
    public function dueDates(SavingsCycle $cycle, Periodicity $periodicity, int $installmentsPerCycle): array
    {
        if (!$periodicity->isRecurring()) {
            return [];
        }

        $dueDates = [];
        $dueDate = $cycle->startsAt;
        for ($i = 0; $i < $installmentsPerCycle; ++$i) {
            $dueDates[] = $dueDate;
            $dueDate = $dueDate->add($periodicity->dateInterval());
        }

        return $dueDates;
    }

    public function expectedInstallmentsFor(Membership $membership, SavingsCycle $cycle, Periodicity $periodicity, int $installmentsPerCycle, \DateTimeImmutable $now): int
    {
        $count = 0;
        foreach ($this->dueDates($cycle, $periodicity, $installmentsPerCycle) as $dueDate) {
            if ($dueDate >= $membership->joinedAt && $dueDate <= $now) {
                ++$count;
            }
        }

        return $count;
    }

    public function missedInstallmentsFor(Membership $membership, SavingsCycle $cycle, Periodicity $periodicity, int $installmentsPerCycle, \DateTimeImmutable $now, int $paidCount): int
    {
        $expected = $this->expectedInstallmentsFor($membership, $cycle, $periodicity, $installmentsPerCycle, $now);

        return max(0, $expected - $paidCount);
    }

    public function nextDueDateFor(Membership $membership, SavingsCycle $cycle, Periodicity $periodicity, int $installmentsPerCycle, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $threshold = max($membership->joinedAt, $now);

        foreach ($this->dueDates($cycle, $periodicity, $installmentsPerCycle) as $dueDate) {
            if ($dueDate > $threshold) {
                return $dueDate;
            }
        }

        return null;
    }
}
