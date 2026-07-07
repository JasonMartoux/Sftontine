<?php

declare(strict_types=1);

namespace App\Tests\Application\Tontine\UseCase;

use App\Application\Tontine\Port\TontineGroupRepositoryInterface;
use App\Application\Tontine\UseCase\GroupPotReader;
use App\Application\Vault\Port\YieldSnapshotRepositoryInterface;
use App\Domain\Deposit\TransactionHash;
use App\Domain\Identity\User;
use App\Domain\Identity\WalletAddress;
use App\Domain\Tontine\Periodicity;
use App\Domain\Tontine\TontineGroup;
use App\Domain\Vault\VaultPosition;
use App\Domain\Vault\YieldSnapshot;
use BcMath\Number;
use Money\Currency;
use Money\Money;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class GroupPotReaderTest extends TestCase
{
    private const ALICE_WALLET = '0x1111111111111111111111111111111111111111';
    private const BOB_WALLET = '0x2222222222222222222222222222222222222222';
    private const CREATED_AT = '2026-01-01T00:00:00+00:00';
    private const NOW = '2026-01-08T00:00:00+00:00';

    public function testReturnsNullForUnknownGroup(): void
    {
        $groups = $this->createStub(TontineGroupRepositoryInterface::class);
        $groups->method('find')->willReturn(null);

        $reader = new GroupPotReader($groups, $this->snapshots(500), $this->clock());

        self::assertNull($reader->read(1));
    }

    public function testBuildsPotViewWithMembersAprAndYield(): void
    {
        $alice = self::alice();
        $bob = self::bob();
        $group = TontineGroup::create($alice, 'Tontine famille', self::usdc('25000000'), Periodicity::Weekly, 2, new \DateTimeImmutable(self::CREATED_AT));
        $group->join($bob, new \DateTimeImmutable(self::CREATED_AT));

        // Alice pays both installments (Jan 1 and Jan 8) — fully on time.
        $group->recordContribution($alice, self::hash('a1'), self::usdc('25000000'), new \DateTimeImmutable(self::CREATED_AT));
        $group->recordContribution($alice, self::hash('a2'), self::usdc('25000000'), new \DateTimeImmutable(self::NOW));
        // Bob never contributes — late on both installments.

        $groups = $this->createStub(TontineGroupRepositoryInterface::class);
        $groups->method('find')->willReturn($group);

        $reader = new GroupPotReader($groups, $this->snapshots(500), $this->clock());
        $view = $reader->read(1);

        self::assertNotNull($view);
        self::assertSame(1, $view->groupId);
        self::assertSame('Tontine famille', $view->name);
        self::assertSame('50.000000', $view->potTotalDisplay);
        self::assertSame(500, $view->aprBasisPoints);
        self::assertSame(1, $view->cycleNumber);
        self::assertEquals(new \DateTimeImmutable('2026-01-15T00:00:00+00:00'), $view->cycleEndsAt);
        self::assertNotSame('0.000000', $view->estimatedYieldDisplay);

        self::assertCount(2, $view->members);
        $byWallet = [];
        foreach ($view->members as $member) {
            $byWallet[$member->walletAddress] = $member;
        }

        $aliceView = $byWallet[self::ALICE_WALLET];
        self::assertSame(2, $aliceView->paidInstallments);
        self::assertSame(2, $aliceView->expectedInstallments);
        self::assertSame(0, $aliceView->missedInstallments);
        self::assertFalse($aliceView->isLate);
        self::assertNotSame('0.000000', $aliceView->yieldShareDisplay);

        $bobView = $byWallet[self::BOB_WALLET];
        self::assertSame(0, $bobView->paidInstallments);
        self::assertSame(2, $bobView->expectedInstallments);
        self::assertSame(2, $bobView->missedInstallments);
        self::assertTrue($bobView->isLate);
        self::assertSame('0.000000', $bobView->yieldShareDisplay);
    }

    public function testDefaultsAprToZeroWithoutASnapshot(): void
    {
        $alice = self::alice();
        $group = TontineGroup::create($alice, 'Tontine famille', self::usdc('25000000'), Periodicity::Weekly, 2, new \DateTimeImmutable(self::CREATED_AT));

        $groups = $this->createStub(TontineGroupRepositoryInterface::class);
        $groups->method('find')->willReturn($group);

        $snapshots = $this->createStub(YieldSnapshotRepositoryInterface::class);
        $snapshots->method('findMostRecent')->willReturn(null);

        $reader = new GroupPotReader($groups, $snapshots, $this->clock());
        $view = $reader->read(1);

        self::assertNotNull($view);
        self::assertSame(0, $view->aprBasisPoints);
        self::assertSame('0.000000', $view->estimatedYieldDisplay);
    }

    private static function alice(): User
    {
        return User::registerFromPrivy('did:privy:alice', 'alice@example.com', new WalletAddress(self::ALICE_WALLET));
    }

    private static function bob(): User
    {
        return User::registerFromPrivy('did:privy:bob', 'bob@example.com', new WalletAddress(self::BOB_WALLET));
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

    private function snapshots(int $aprBasisPoints): YieldSnapshotRepositoryInterface
    {
        $snapshot = YieldSnapshot::fromPosition(new WalletAddress(self::ALICE_WALLET), new VaultPosition(
            shares: new Number('0'),
            principal: self::usdc('0'),
            yieldReceived: new Number('0'),
            flowRate: new Number('0'),
            connected: true,
            paused: false,
            aprBasisPoints: new Number((string) $aprBasisPoints),
        ));

        $snapshots = $this->createStub(YieldSnapshotRepositoryInterface::class);
        $snapshots->method('findMostRecent')->willReturn($snapshot);

        return $snapshots;
    }

    private function clock(): ClockInterface
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable(self::NOW));

        return $clock;
    }
}
