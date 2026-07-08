<?php

declare(strict_types=1);

namespace App\Tests\Domain\Tontine;

use App\Domain\Deposit\TransactionHash;
use App\Domain\Identity\User;
use App\Domain\Identity\WalletAddress;
use App\Domain\Tontine\Contribution;
use App\Domain\Tontine\Membership;
use App\Domain\Tontine\Periodicity;
use App\Domain\Tontine\SavingsCycle;
use App\Domain\Tontine\TontineGroup;
use App\Domain\Tontine\YieldDistribution;
use BcMath\Number;
use Money\Currency;
use Money\Money;
use PHPUnit\Framework\TestCase;

final class YieldDistributionTest extends TestCase
{
    private YieldDistribution $yieldDistribution;

    protected function setUp(): void
    {
        $this->yieldDistribution = new YieldDistribution();
    }

    public function testEstimateAccruedYieldForOneYearAtFivePercentApr(): void
    {
        $membership = self::makeMembership();
        $contributedAt = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $now = $contributedAt->add(new \DateInterval('P365D'));
        $contribution = self::makeContribution($membership, self::usdc('100000000'), $contributedAt);

        $yield = $this->yieldDistribution->estimateAccruedYield([$contribution], aprBasisPoints: 500, now: $now, cap: $now);

        self::assertTrue($yield->equals(self::usdc('5000000'))); // 5 USDC
    }

    public function testEstimateAccruedYieldIsZeroWhenAprIsZero(): void
    {
        $membership = self::makeMembership();
        $contributedAt = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $now = $contributedAt->add(new \DateInterval('P365D'));
        $contribution = self::makeContribution($membership, self::usdc('100000000'), $contributedAt);

        $yield = $this->yieldDistribution->estimateAccruedYield([$contribution], aprBasisPoints: 0, now: $now, cap: $now);

        self::assertTrue($yield->isZero());
    }

    public function testEstimateAccruedYieldIsZeroWithoutContributions(): void
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');

        $yield = $this->yieldDistribution->estimateAccruedYield([], aprBasisPoints: 500, now: $now, cap: $now);

        self::assertTrue($yield->isZero());
    }

    public function testEstimateAccruedYieldCapsActiveTimeAtCycleEnd(): void
    {
        $membership = self::makeMembership();
        $contributedAt = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $cap = $contributedAt->add(new \DateInterval('P365D'));
        $farFuture = $cap->add(new \DateInterval('P365D'));
        $contribution = self::makeContribution($membership, self::usdc('100000000'), $contributedAt);

        $yield = $this->yieldDistribution->estimateAccruedYield([$contribution], aprBasisPoints: 500, now: $farFuture, cap: $cap);

        self::assertTrue($yield->equals(self::usdc('5000000'))); // capped to 1 year, not 2
    }

    public function testEstimateAccruedYieldIsZeroForContributionMadeExactlyNow(): void
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $contribution = self::makeContribution(self::makeMembership(), self::usdc('100000000'), $now);

        $yield = $this->yieldDistribution->estimateAccruedYield([$contribution], aprBasisPoints: 500, now: $now, cap: $now);

        self::assertTrue($yield->isZero());
    }

    public function testWeightsAccumulatePerMembershipAcrossMultipleContributions(): void
    {
        $membership = self::makeMembership();
        $now = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $c1 = self::makeContribution($membership, self::usdc('100000000'), $now->modify('-10 days'));
        $c2 = self::makeContribution($membership, self::usdc('50000000'), $now->modify('-5 days'));

        $weights = $this->yieldDistribution->weights([$c1, $c2], $now, $now);

        $key = spl_object_id($membership);
        self::assertArrayHasKey($key, $weights);

        $expected = (new Number('100000000'))->mul((string) (10 * 86400))
            ->add((new Number('50000000'))->mul((string) (5 * 86400)));
        self::assertSame((string) $expected, (string) $weights[$key]);
    }

    public function testSplitDistributesProRataByWeight(): void
    {
        $memberA = self::makeMembership();
        $memberB = self::makeMembership();

        $weights = [
            spl_object_id($memberA) => new Number('2'),
            spl_object_id($memberB) => new Number('1'),
        ];

        $shares = $this->yieldDistribution->split(self::usdc('300'), $weights);

        self::assertTrue($shares[spl_object_id($memberA)]->equals(self::usdc('200')));
        self::assertTrue($shares[spl_object_id($memberB)]->equals(self::usdc('100')));
    }

    public function testSplitDistributesRemainderByLargestFractionAndSumsExactlyToTotal(): void
    {
        $memberA = self::makeMembership();
        $memberB = self::makeMembership();
        $memberC = self::makeMembership();

        $weights = [
            spl_object_id($memberA) => new Number('1'),
            spl_object_id($memberB) => new Number('1'),
            spl_object_id($memberC) => new Number('1'),
        ];

        $shares = $this->yieldDistribution->split(self::usdc('100'), $weights);

        $sum = $shares[spl_object_id($memberA)]
            ->add($shares[spl_object_id($memberB)])
            ->add($shares[spl_object_id($memberC)]);
        self::assertTrue($sum->equals(self::usdc('100')));
    }

    public function testSplitGivesZeroSharesWhenAllWeightsAreZero(): void
    {
        $memberA = self::makeMembership();
        $memberB = self::makeMembership();

        $weights = [
            spl_object_id($memberA) => new Number('0'),
            spl_object_id($memberB) => new Number('0'),
        ];

        $shares = $this->yieldDistribution->split(self::usdc('100'), $weights);

        self::assertTrue($shares[spl_object_id($memberA)]->isZero());
        self::assertTrue($shares[spl_object_id($memberB)]->isZero());
    }

    private static function makeMembership(): Membership
    {
        $group = TontineGroup::create(
            User::registerFromPrivy('did:privy:alice', 'alice@example.com', new WalletAddress('0x1111111111111111111111111111111111111111')),
            'Tontine famille',
            self::usdc('25000000'),
            Periodicity::Weekly,
            4,
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
        $user = User::registerFromPrivy('did:privy:member-'.spl_object_id($group), null, new WalletAddress('0x3333333333333333333333333333333333333333'));

        return Membership::member($group, $user, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    }

    private static function makeContribution(Membership $membership, Money $amount, \DateTimeImmutable $contributedAt): Contribution
    {
        $cycle = SavingsCycle::open($membership->group, 1, $contributedAt, $contributedAt->add(new \DateInterval('P365D')));

        return Contribution::record($membership, $cycle, new TransactionHash('0x'.str_repeat('a1', 32)), $amount, $contributedAt);
    }

    /**
     * @param numeric-string $minorUnits
     */
    private static function usdc(string $minorUnits): Money
    {
        return new Money($minorUnits, new Currency('USDC'));
    }
}
