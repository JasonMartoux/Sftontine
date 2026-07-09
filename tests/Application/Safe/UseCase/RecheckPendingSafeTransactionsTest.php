<?php

declare(strict_types=1);

namespace App\Tests\Application\Safe\UseCase;

use App\Application\Safe\Dto\SafeDeploymentReceipt;
use App\Application\Safe\Port\SafeReceiptReaderInterface;
use App\Application\Safe\Port\SafeTransactionRepositoryInterface;
use App\Application\Safe\UseCase\RecheckPendingSafeTransactions;
use App\Application\Tontine\Port\TontineGroupRepositoryInterface;
use App\Application\Vault\Exception\BlockchainCallException;
use App\Domain\Deposit\TransactionHash;
use App\Domain\Identity\User;
use App\Domain\Identity\WalletAddress;
use App\Domain\Safe\SafeTransaction;
use App\Domain\Safe\SafeTransactionStatus;
use App\Domain\Tontine\Periodicity;
use App\Domain\Tontine\TontineGroup;
use Money\Currency;
use Money\Money;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RecheckPendingSafeTransactionsTest extends TestCase
{
    private const ADMIN_WALLET = '0x1111111111111111111111111111111111111111';
    private const SAFE_ADDRESS = '0x4444444444444444444444444444444444444444';

    public function testConfirmsAPendingDeploymentAndProvisionsTheGroup(): void
    {
        $group = $this->makeGroup();
        $pending = SafeTransaction::pendingDeployment(1, $this->txHash('a1'));

        $safeTransactions = $this->createMock(SafeTransactionRepositoryInterface::class);
        $safeTransactions->method('findAllPending')->willReturn([$pending]);
        $safeTransactions->expects(self::once())->method('save')->with($pending);

        $receiptReader = $this->createStub(SafeReceiptReaderInterface::class);
        $receiptReader->method('getDeploymentReceipt')->willReturn(new SafeDeploymentReceipt(true, self::SAFE_ADDRESS));

        $groups = $this->createMock(TontineGroupRepositoryInterface::class);
        $groups->method('find')->willReturn($group);
        $groups->expects(self::once())->method('save')->with($group);

        (new RecheckPendingSafeTransactions($safeTransactions, $groups, $receiptReader, new NullLogger()))();

        self::assertSame(SafeTransactionStatus::Confirmed, $pending->status);
        self::assertTrue($group->hasSafe());
    }

    public function testLeavesADeploymentPendingWhenNoReceiptYet(): void
    {
        $pending = SafeTransaction::pendingDeployment(1, $this->txHash('a1'));

        $safeTransactions = $this->createMock(SafeTransactionRepositoryInterface::class);
        $safeTransactions->method('findAllPending')->willReturn([$pending]);
        $safeTransactions->expects(self::never())->method('save');

        $receiptReader = $this->createStub(SafeReceiptReaderInterface::class);
        $receiptReader->method('getDeploymentReceipt')->willReturn(null);

        $groups = $this->createMock(TontineGroupRepositoryInterface::class);
        $groups->expects(self::never())->method('save');

        (new RecheckPendingSafeTransactions($safeTransactions, $groups, $receiptReader, new NullLogger()))();

        self::assertTrue($pending->isPending());
    }

    public function testOneTransactionsRpcFailureDoesNotAbortTheOthers(): void
    {
        $failing = SafeTransaction::pendingDeployment(1, $this->txHash('a1'));
        $succeeding = SafeTransaction::pendingDeployment(2, $this->txHash('b2'));

        $safeTransactions = $this->createMock(SafeTransactionRepositoryInterface::class);
        $safeTransactions->method('findAllPending')->willReturn([$failing, $succeeding]);
        $safeTransactions->expects(self::once())->method('save')->with($succeeding);

        $receiptReader = $this->createStub(SafeReceiptReaderInterface::class);
        $receiptReader->method('getDeploymentReceipt')->willReturnCallback(
            static fn (TransactionHash $txHash): SafeDeploymentReceipt => $txHash->equals($failing->txHash)
                ? throw BlockchainCallException::rpcError('timeout') : new SafeDeploymentReceipt(false, null),
        );

        $groups = $this->createStub(TontineGroupRepositoryInterface::class);

        (new RecheckPendingSafeTransactions($safeTransactions, $groups, $receiptReader, new NullLogger()))();

        self::assertTrue($failing->isPending());
        self::assertSame(SafeTransactionStatus::Failed, $succeeding->status);
    }

    private function makeGroup(): TontineGroup
    {
        $admin = User::registerFromPrivy('did:privy:admin', 'admin@example.com', new WalletAddress(self::ADMIN_WALLET));

        return TontineGroup::create($admin, 'Tontine famille', new Money('25000000', new Currency('USDC')), Periodicity::Weekly, 12, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    }

    private function txHash(string $pair): TransactionHash
    {
        return new TransactionHash('0x'.str_repeat($pair, 32));
    }
}
