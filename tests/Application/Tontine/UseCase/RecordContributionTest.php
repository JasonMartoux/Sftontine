<?php

declare(strict_types=1);

namespace App\Tests\Application\Tontine\UseCase;

use App\Application\Deposit\Port\DepositTransactionRepositoryInterface;
use App\Application\Tontine\Port\TontineGroupRepositoryInterface;
use App\Application\Tontine\UseCase\RecordContribution;
use App\Application\Tontine\UseCase\RecordContributionError;
use App\Domain\Deposit\DepositEvent;
use App\Domain\Deposit\DepositTransaction;
use App\Domain\Deposit\TransactionHash;
use App\Domain\Deposit\TransactionReceipt;
use App\Domain\Identity\User;
use App\Domain\Identity\WalletAddress;
use App\Domain\Tontine\Periodicity;
use App\Domain\Tontine\TontineGroup;
use BcMath\Number;
use Money\Currency;
use Money\Money;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final class RecordContributionTest extends TestCase
{
    private const ALICE_WALLET = '0x1111111111111111111111111111111111111111';
    private const NOW = '2026-01-02T00:00:00+00:00';

    public function testRecordsContributionOnSuccess(): void
    {
        $alice = self::alice();
        $group = self::makeGroup($alice);
        $txHash = self::hash('a1');
        $deposit = self::confirmedDeposit($txHash, self::ALICE_WALLET, '25000000');

        $groups = $this->createMock(TontineGroupRepositoryInterface::class);
        $groups->expects(self::once())->method('find')->with(1)->willReturn($group);
        $groups->expects(self::once())->method('save')->with($group);

        $deposits = $this->createStub(DepositTransactionRepositoryInterface::class);
        $deposits->method('findByTxHash')->willReturn($deposit);

        $useCase = new RecordContribution($groups, $deposits, $this->clock(), $this->logger());
        $result = $useCase($alice, 1, $txHash);

        self::assertTrue($result->isSuccess);
        self::assertSame(1, $result->value()->groupId);
        self::assertSame('25.000000', $result->value()->amountDisplay);
        self::assertSame((string) $txHash, $result->value()->txHash);
    }

    public function testRejectsUnknownGroupWithoutSaving(): void
    {
        $groups = $this->createMock(TontineGroupRepositoryInterface::class);
        $groups->expects(self::once())->method('find')->with(1)->willReturn(null);
        $groups->expects(self::never())->method('save');

        $deposits = $this->createStub(DepositTransactionRepositoryInterface::class);

        $useCase = new RecordContribution($groups, $deposits, $this->clock(), $this->logger());
        $result = $useCase(self::alice(), 1, self::hash('a1'));

        self::assertFalse($result->isSuccess);
        self::assertSame(RecordContributionError::GroupNotFound, $result->error());
    }

    public function testRejectsNonMemberWithoutSaving(): void
    {
        $group = self::makeGroup(self::alice());

        $groups = $this->createMock(TontineGroupRepositoryInterface::class);
        $groups->expects(self::once())->method('find')->with(1)->willReturn($group);
        $groups->expects(self::never())->method('save');

        $deposits = $this->createStub(DepositTransactionRepositoryInterface::class);

        $useCase = new RecordContribution($groups, $deposits, $this->clock(), $this->logger());
        $result = $useCase(self::bob(), 1, self::hash('a1'));

        self::assertFalse($result->isSuccess);
        self::assertSame(RecordContributionError::NotAMember, $result->error());
    }

    public function testRejectsUnknownDepositWithoutSaving(): void
    {
        $alice = self::alice();
        $group = self::makeGroup($alice);

        $groups = $this->createMock(TontineGroupRepositoryInterface::class);
        $groups->expects(self::once())->method('find')->with(1)->willReturn($group);
        $groups->expects(self::never())->method('save');

        $deposits = $this->createStub(DepositTransactionRepositoryInterface::class);
        $deposits->method('findByTxHash')->willReturn(null);

        $useCase = new RecordContribution($groups, $deposits, $this->clock(), $this->logger());
        $result = $useCase($alice, 1, self::hash('a1'));

        self::assertFalse($result->isSuccess);
        self::assertSame(RecordContributionError::DepositNotFound, $result->error());
    }

    public function testRejectsUnconfirmedDepositWithoutSaving(): void
    {
        $alice = self::alice();
        $group = self::makeGroup($alice);
        $txHash = self::hash('a1');
        $pendingDeposit = DepositTransaction::pending(new WalletAddress(self::ALICE_WALLET), $txHash);

        $groups = $this->createMock(TontineGroupRepositoryInterface::class);
        $groups->expects(self::once())->method('find')->with(1)->willReturn($group);
        $groups->expects(self::never())->method('save');

        $deposits = $this->createStub(DepositTransactionRepositoryInterface::class);
        $deposits->method('findByTxHash')->willReturn($pendingDeposit);

        $useCase = new RecordContribution($groups, $deposits, $this->clock(), $this->logger());
        $result = $useCase($alice, 1, $txHash);

        self::assertFalse($result->isSuccess);
        self::assertSame(RecordContributionError::DepositNotConfirmed, $result->error());
    }

    public function testRejectsWalletMismatchWithoutSaving(): void
    {
        $alice = self::alice();
        $group = self::makeGroup($alice);
        $txHash = self::hash('a1');
        // Confirmed, but for a different wallet than Alice's.
        $deposit = self::confirmedDeposit($txHash, '0x9999999999999999999999999999999999999999', '25000000');

        $groups = $this->createMock(TontineGroupRepositoryInterface::class);
        $groups->expects(self::once())->method('find')->with(1)->willReturn($group);
        $groups->expects(self::never())->method('save');

        $deposits = $this->createStub(DepositTransactionRepositoryInterface::class);
        $deposits->method('findByTxHash')->willReturn($deposit);

        $useCase = new RecordContribution($groups, $deposits, $this->clock(), $this->logger());
        $result = $useCase($alice, 1, $txHash);

        self::assertFalse($result->isSuccess);
        self::assertSame(RecordContributionError::DepositWalletMismatch, $result->error());
    }

    public function testRejectsAmountMismatchWithoutSaving(): void
    {
        $alice = self::alice();
        $group = self::makeGroup($alice);
        $txHash = self::hash('a1');
        $deposit = self::confirmedDeposit($txHash, self::ALICE_WALLET, '24000000');

        $groups = $this->createMock(TontineGroupRepositoryInterface::class);
        $groups->expects(self::once())->method('find')->with(1)->willReturn($group);
        $groups->expects(self::never())->method('save');

        $deposits = $this->createStub(DepositTransactionRepositoryInterface::class);
        $deposits->method('findByTxHash')->willReturn($deposit);

        $useCase = new RecordContribution($groups, $deposits, $this->clock(), $this->logger());
        $result = $useCase($alice, 1, $txHash);

        self::assertFalse($result->isSuccess);
        self::assertSame(RecordContributionError::AmountMismatch, $result->error());
    }

    public function testRejectsAlreadyRecordedTransactionWithoutSaving(): void
    {
        $alice = self::alice();
        $group = self::makeGroup($alice);
        $txHash = self::hash('a1');
        $deposit = self::confirmedDeposit($txHash, self::ALICE_WALLET, '25000000');
        $group->recordContribution($alice, $txHash, new Money('25000000', new Currency('USDC')), new \DateTimeImmutable(self::NOW));

        $groups = $this->createMock(TontineGroupRepositoryInterface::class);
        $groups->expects(self::once())->method('find')->with(1)->willReturn($group);
        $groups->expects(self::never())->method('save');

        $deposits = $this->createStub(DepositTransactionRepositoryInterface::class);
        $deposits->method('findByTxHash')->willReturn($deposit);

        $useCase = new RecordContribution($groups, $deposits, $this->clock(), $this->logger());
        $result = $useCase($alice, 1, $txHash);

        self::assertFalse($result->isSuccess);
        self::assertSame(RecordContributionError::AlreadyRecorded, $result->error());
    }

    public function testRejectsContributionWhenCycleClosedWithoutSaving(): void
    {
        $alice = self::alice();
        $group = self::makeGroup($alice);
        $group->closeCurrentCycle(new \DateTimeImmutable(self::NOW));
        $txHash = self::hash('a1');
        $deposit = self::confirmedDeposit($txHash, self::ALICE_WALLET, '25000000');

        $groups = $this->createMock(TontineGroupRepositoryInterface::class);
        $groups->expects(self::once())->method('find')->with(1)->willReturn($group);
        $groups->expects(self::never())->method('save');

        $deposits = $this->createStub(DepositTransactionRepositoryInterface::class);
        $deposits->method('findByTxHash')->willReturn($deposit);

        $useCase = new RecordContribution($groups, $deposits, $this->clock(), $this->logger());
        $result = $useCase($alice, 1, $txHash);

        self::assertFalse($result->isSuccess);
        self::assertSame(RecordContributionError::CycleClosed, $result->error());
    }

    private static function makeGroup(User $creator): TontineGroup
    {
        return TontineGroup::create(
            $creator,
            'Tontine famille',
            new Money('25000000', new Currency('USDC')),
            Periodicity::Weekly,
            12,
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
    }

    /**
     * @param numeric-string $assetsMinorUnits
     */
    private static function confirmedDeposit(TransactionHash $txHash, string $wallet, string $assetsMinorUnits): DepositTransaction
    {
        $deposit = DepositTransaction::pending(new WalletAddress($wallet), $txHash);
        $deposit->applyReceipt(new TransactionReceipt(
            success: true,
            depositEvent: new DepositEvent($wallet, $wallet, new Number($assetsMinorUnits), new Number($assetsMinorUnits)),
        ));

        return $deposit;
    }

    private static function alice(): User
    {
        return User::registerFromPrivy('did:privy:alice', 'alice@example.com', new WalletAddress(self::ALICE_WALLET));
    }

    private static function bob(): User
    {
        return User::registerFromPrivy('did:privy:bob', 'bob@example.com', new WalletAddress('0x2222222222222222222222222222222222222222'));
    }

    private static function hash(string $pair): TransactionHash
    {
        return new TransactionHash('0x'.str_repeat($pair, 32));
    }

    private function clock(): ClockInterface
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable(self::NOW));

        return $clock;
    }

    private function logger(): LoggerInterface
    {
        return $this->createStub(LoggerInterface::class);
    }
}
