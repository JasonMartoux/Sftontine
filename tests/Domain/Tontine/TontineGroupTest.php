<?php

declare(strict_types=1);

namespace App\Tests\Domain\Tontine;

use App\Domain\Deposit\TransactionHash;
use App\Domain\Identity\User;
use App\Domain\Identity\WalletAddress;
use App\Domain\Tontine\Event\ContributionRecorded;
use App\Domain\Tontine\Event\CycleClosed;
use App\Domain\Tontine\Event\MemberJoined;
use App\Domain\Tontine\Exception\AlreadyMemberException;
use App\Domain\Tontine\Exception\ContributionAlreadyRecordedException;
use App\Domain\Tontine\Exception\ContributionAmountMismatchException;
use App\Domain\Tontine\Exception\CycleClosedException;
use App\Domain\Tontine\Exception\GroupClosedException;
use App\Domain\Tontine\Exception\InvalidContributionAmountException;
use App\Domain\Tontine\Exception\InvalidCycleLengthException;
use App\Domain\Tontine\Exception\InvalidGroupNameException;
use App\Domain\Tontine\Exception\NotAMemberException;
use App\Domain\Tontine\MembershipRole;
use App\Domain\Tontine\Periodicity;
use App\Domain\Tontine\SavingsCycleStatus;
use App\Domain\Tontine\TontineGroup;
use App\Domain\Tontine\TontineGroupStatus;
use Money\Currency;
use Money\Money;
use PHPUnit\Framework\TestCase;

final class TontineGroupTest extends TestCase
{
    private const NOW = '2026-01-01T00:00:00+00:00';

    public function testCreateInitializesGroupWithAdminCreatorAndFirstCycle(): void
    {
        $now = new \DateTimeImmutable(self::NOW);
        $creator = self::alice();

        $group = TontineGroup::create($creator, 'Tontine famille', self::usdc('25000000'), Periodicity::Weekly, 12, $now);

        self::assertSame('Tontine famille', $group->name);
        self::assertTrue($group->contributionAmount->equals(self::usdc('25000000')));
        self::assertSame('25.000000', $group->contributionAmountDisplay());
        self::assertSame(Periodicity::Weekly, $group->periodicity);
        self::assertSame(12, $group->installmentsPerCycle);
        self::assertSame(TontineGroupStatus::Active, $group->status);
        self::assertSame(1, $group->memberCount);

        $membership = $group->membershipFor($creator);
        self::assertNotNull($membership);
        self::assertSame(MembershipRole::Admin, $membership->role);
        self::assertEquals($now, $membership->joinedAt);

        $cycle = $group->currentCycle();
        self::assertNotNull($cycle);
        self::assertSame(1, $cycle->number);
        self::assertSame(SavingsCycleStatus::Open, $cycle->status);
        self::assertEquals($now, $cycle->startsAt);
        self::assertEquals($now->add(new \DateInterval('P84D')), $cycle->endsAt); // 12 × P7D

        $events = $group->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(MemberJoined::class, $events[0]);
    }

    public function testCreateRejectsTooShortName(): void
    {
        $this->expectException(InvalidGroupNameException::class);

        TontineGroup::create(self::alice(), 'ab', self::usdc('25000000'), Periodicity::Weekly, 12, new \DateTimeImmutable(self::NOW));
    }

    public function testCreateRejectsTooLongName(): void
    {
        $this->expectException(InvalidGroupNameException::class);

        TontineGroup::create(self::alice(), str_repeat('a', 101), self::usdc('25000000'), Periodicity::Weekly, 12, new \DateTimeImmutable(self::NOW));
    }

    public function testCreateRejectsNonPositiveAmount(): void
    {
        $this->expectException(InvalidContributionAmountException::class);

        TontineGroup::create(self::alice(), 'Tontine famille', self::usdc('0'), Periodicity::Weekly, 12, new \DateTimeImmutable(self::NOW));
    }

    public function testCreateRejectsTooFewInstallments(): void
    {
        $this->expectException(InvalidCycleLengthException::class);

        TontineGroup::create(self::alice(), 'Tontine famille', self::usdc('25000000'), Periodicity::Weekly, 1, new \DateTimeImmutable(self::NOW));
    }

    public function testCreateRejectsTooManyInstallments(): void
    {
        $this->expectException(InvalidCycleLengthException::class);

        TontineGroup::create(self::alice(), 'Tontine famille', self::usdc('25000000'), Periodicity::Weekly, 53, new \DateTimeImmutable(self::NOW));
    }

    public function testJoinAddsMemberWithMemberRoleAndRecordsEvent(): void
    {
        $group = self::makeGroup(self::alice());
        $group->releaseEvents();
        $bob = self::bob();

        $membership = $group->join($bob, new \DateTimeImmutable('2026-01-05T00:00:00+00:00'));

        self::assertSame(2, $group->memberCount);
        self::assertSame(MembershipRole::Member, $membership->role);
        self::assertSame($membership, $group->membershipFor($bob));

        $events = $group->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(MemberJoined::class, $events[0]);
    }

    public function testJoinRejectsExistingMember(): void
    {
        $group = self::makeGroup(self::alice());
        $bob = self::bob();
        $group->join($bob, new \DateTimeImmutable('2026-01-05T00:00:00+00:00'));

        $this->expectException(AlreadyMemberException::class);

        $group->join($bob, new \DateTimeImmutable('2026-01-06T00:00:00+00:00'));
    }

    public function testJoinRejectsCreator(): void
    {
        $creator = self::alice();
        $group = self::makeGroup($creator);

        $this->expectException(AlreadyMemberException::class);

        $group->join($creator, new \DateTimeImmutable('2026-01-05T00:00:00+00:00'));
    }

    public function testJoinRejectedWhenGroupClosed(): void
    {
        $group = self::makeGroup(self::alice());
        $group->close(new \DateTimeImmutable('2026-01-10T00:00:00+00:00'));

        $this->expectException(GroupClosedException::class);

        $group->join(self::bob(), new \DateTimeImmutable('2026-01-11T00:00:00+00:00'));
    }

    public function testRecordContributionAddsToPotAndRecordsEvent(): void
    {
        $creator = self::alice();
        $group = self::makeGroup($creator);
        $group->releaseEvents();
        $now = new \DateTimeImmutable('2026-01-02T00:00:00+00:00');

        $contribution = $group->recordContribution($creator, self::hash('a1'), self::usdc('25000000'), $now);

        self::assertTrue($contribution->amount->equals(self::usdc('25000000')));
        self::assertSame('25.000000', $contribution->amountDisplay());
        self::assertTrue($contribution->txHash->equals(self::hash('a1')));
        self::assertEquals($now, $contribution->contributedAt);
        self::assertTrue($group->potTotal()->equals(self::usdc('25000000')));

        $events = $group->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(ContributionRecorded::class, $events[0]);
    }

    public function testPotTotalSumsAllContributions(): void
    {
        $creator = self::alice();
        $group = self::makeGroup($creator);
        $bob = self::bob();
        $group->join($bob, new \DateTimeImmutable('2026-01-02T00:00:00+00:00'));

        $group->recordContribution($creator, self::hash('a1'), self::usdc('25000000'), new \DateTimeImmutable('2026-01-02T01:00:00+00:00'));
        $group->recordContribution($bob, self::hash('b2'), self::usdc('25000000'), new \DateTimeImmutable('2026-01-03T00:00:00+00:00'));

        self::assertTrue($group->potTotal()->equals(self::usdc('50000000')));
    }

    public function testRecordContributionRejectsNonMember(): void
    {
        $group = self::makeGroup(self::alice());

        $this->expectException(NotAMemberException::class);

        $group->recordContribution(self::bob(), self::hash('a1'), self::usdc('25000000'), new \DateTimeImmutable('2026-01-02T00:00:00+00:00'));
    }

    public function testRecordContributionRejectsWrongAmount(): void
    {
        $creator = self::alice();
        $group = self::makeGroup($creator);

        $this->expectException(ContributionAmountMismatchException::class);

        $group->recordContribution($creator, self::hash('a1'), self::usdc('24000000'), new \DateTimeImmutable('2026-01-02T00:00:00+00:00'));
    }

    public function testRecordContributionRejectsReusedTransactionHash(): void
    {
        $creator = self::alice();
        $group = self::makeGroup($creator);
        $bob = self::bob();
        $group->join($bob, new \DateTimeImmutable('2026-01-02T00:00:00+00:00'));
        $group->recordContribution($creator, self::hash('a1'), self::usdc('25000000'), new \DateTimeImmutable('2026-01-02T01:00:00+00:00'));

        $this->expectException(ContributionAlreadyRecordedException::class);

        $group->recordContribution($bob, self::hash('a1'), self::usdc('25000000'), new \DateTimeImmutable('2026-01-03T00:00:00+00:00'));
    }

    public function testRecordContributionRejectedWhenCycleClosed(): void
    {
        $creator = self::alice();
        $group = self::makeGroup($creator);
        $group->closeCurrentCycle(new \DateTimeImmutable('2026-01-10T00:00:00+00:00'));

        $this->expectException(CycleClosedException::class);

        $group->recordContribution($creator, self::hash('a1'), self::usdc('25000000'), new \DateTimeImmutable('2026-01-11T00:00:00+00:00'));
    }

    public function testCloseCurrentCycleClosesItAndRecordsEvent(): void
    {
        $group = self::makeGroup(self::alice());
        $group->releaseEvents();

        $group->closeCurrentCycle(new \DateTimeImmutable('2026-01-10T00:00:00+00:00'));

        self::assertNull($group->currentCycle());

        $events = $group->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(CycleClosed::class, $events[0]);
    }

    public function testCloseCurrentCycleRejectedWhenNoOpenCycle(): void
    {
        $group = self::makeGroup(self::alice());
        $group->closeCurrentCycle(new \DateTimeImmutable('2026-01-10T00:00:00+00:00'));

        $this->expectException(CycleClosedException::class);

        $group->closeCurrentCycle(new \DateTimeImmutable('2026-01-11T00:00:00+00:00'));
    }

    public function testReleaseEventsClearsRecordedEvents(): void
    {
        $group = self::makeGroup(self::alice());

        self::assertNotEmpty($group->releaseEvents());
        self::assertSame([], $group->releaseEvents());
    }

    public function testPotTotalIsZeroWithoutContributions(): void
    {
        self::assertTrue(self::makeGroup(self::alice())->potTotal()->isZero());
    }

    private static function makeGroup(User $creator): TontineGroup
    {
        return TontineGroup::create(
            $creator,
            'Tontine famille',
            self::usdc('25000000'),
            Periodicity::Weekly,
            12,
            new \DateTimeImmutable(self::NOW),
        );
    }

    private static function alice(): User
    {
        return User::registerFromPrivy('did:privy:alice', 'alice@example.com', new WalletAddress('0x1111111111111111111111111111111111111111'));
    }

    private static function bob(): User
    {
        return User::registerFromPrivy('did:privy:bob', 'bob@example.com', new WalletAddress('0x2222222222222222222222222222222222222222'));
    }

    /**
     * @param numeric-string $minorUnits
     */
    private static function usdc(string $minorUnits): Money
    {
        return new Money($minorUnits, new Currency('USDC'));
    }

    private static function hash(string $pair): TransactionHash
    {
        return new TransactionHash('0x'.str_repeat($pair, 32));
    }
}
