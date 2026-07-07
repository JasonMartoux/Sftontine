<?php

declare(strict_types=1);

namespace App\Tests\Domain\Tontine;

use App\Domain\Identity\User;
use App\Domain\Identity\WalletAddress;
use App\Domain\Tontine\ContributionSchedule;
use App\Domain\Tontine\Membership;
use App\Domain\Tontine\Periodicity;
use App\Domain\Tontine\SavingsCycle;
use App\Domain\Tontine\TontineGroup;
use Money\Currency;
use Money\Money;
use PHPUnit\Framework\TestCase;

final class ContributionScheduleTest extends TestCase
{
    private ContributionSchedule $schedule;

    protected function setUp(): void
    {
        $this->schedule = new ContributionSchedule();
    }

    public function testDueDatesAreSpacedByPeriodicityStartingAtCycleStart(): void
    {
        $group = self::makeGroup();
        $cycle = self::makeCycle($group, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'), new \DateTimeImmutable('2026-01-29T00:00:00+00:00'), 4);

        $dueDates = $this->schedule->dueDates($cycle, Periodicity::Weekly, 4);

        self::assertEquals([
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            new \DateTimeImmutable('2026-01-08T00:00:00+00:00'),
            new \DateTimeImmutable('2026-01-15T00:00:00+00:00'),
            new \DateTimeImmutable('2026-01-22T00:00:00+00:00'),
        ], $dueDates);
    }

    public function testExpectedInstallmentsForFounderCountsAllPastDueDates(): void
    {
        $group = self::makeGroup();
        $cycle = self::makeCycle($group, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'), new \DateTimeImmutable('2026-01-29T00:00:00+00:00'), 4);
        $membership = self::makeMembership(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        $expected = $this->schedule->expectedInstallmentsFor($membership, $cycle, Periodicity::Weekly, 4, new \DateTimeImmutable('2026-01-16T00:00:00+00:00'));

        self::assertSame(3, $expected); // Jan 1, 8, 15 are due; Jan 22 is not yet
    }

    public function testExpectedInstallmentsForMidCycleJoinerExcludesPastDueDates(): void
    {
        $group = self::makeGroup();
        $cycle = self::makeCycle($group, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'), new \DateTimeImmutable('2026-01-29T00:00:00+00:00'), 4);
        // Joined after the Jan 1 and Jan 8 due dates have already passed.
        $membership = self::makeMembership(new \DateTimeImmutable('2026-01-10T00:00:00+00:00'));

        $expected = $this->schedule->expectedInstallmentsFor($membership, $cycle, Periodicity::Weekly, 4, new \DateTimeImmutable('2026-01-16T00:00:00+00:00'));

        self::assertSame(1, $expected); // only Jan 15 is due after joining
    }

    public function testMissedInstallmentsIsExpectedMinusPaidFlooredAtZero(): void
    {
        $group = self::makeGroup();
        $cycle = self::makeCycle($group, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'), new \DateTimeImmutable('2026-01-29T00:00:00+00:00'), 4);
        $membership = self::makeMembership(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $now = new \DateTimeImmutable('2026-01-16T00:00:00+00:00');

        self::assertSame(2, $this->schedule->missedInstallmentsFor($membership, $cycle, Periodicity::Weekly, 4, $now, paidCount: 1));
        self::assertSame(0, $this->schedule->missedInstallmentsFor($membership, $cycle, Periodicity::Weekly, 4, $now, paidCount: 5));
    }

    public function testNextDueDateForReturnsFirstUnduePastDate(): void
    {
        $group = self::makeGroup();
        $cycle = self::makeCycle($group, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'), new \DateTimeImmutable('2026-01-29T00:00:00+00:00'), 4);
        $membership = self::makeMembership(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        $next = $this->schedule->nextDueDateFor($membership, $cycle, Periodicity::Weekly, 4, new \DateTimeImmutable('2026-01-16T00:00:00+00:00'));

        self::assertEquals(new \DateTimeImmutable('2026-01-22T00:00:00+00:00'), $next);
    }

    public function testNextDueDateForReturnsNullWhenAllDueDatesPassed(): void
    {
        $group = self::makeGroup();
        $cycle = self::makeCycle($group, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'), new \DateTimeImmutable('2026-01-29T00:00:00+00:00'), 4);
        $membership = self::makeMembership(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        $next = $this->schedule->nextDueDateFor($membership, $cycle, Periodicity::Weekly, 4, new \DateTimeImmutable('2026-02-01T00:00:00+00:00'));

        self::assertNull($next);
    }

    private static function makeGroup(): TontineGroup
    {
        return TontineGroup::create(
            User::registerFromPrivy('did:privy:alice', 'alice@example.com', new WalletAddress('0x1111111111111111111111111111111111111111')),
            'Tontine famille',
            new Money('25000000', new Currency('USDC')),
            Periodicity::Weekly,
            4,
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
    }

    private static function makeCycle(TontineGroup $group, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, int $number): SavingsCycle
    {
        return SavingsCycle::open($group, $number, $startsAt, $endsAt);
    }

    private static function makeMembership(\DateTimeImmutable $joinedAt): Membership
    {
        $user = User::registerFromPrivy('did:privy:bob', 'bob@example.com', new WalletAddress('0x2222222222222222222222222222222222222222'));

        return Membership::member(self::makeGroup(), $user, $joinedAt);
    }
}
