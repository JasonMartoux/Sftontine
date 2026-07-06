<?php

declare(strict_types=1);

namespace App\Tests\Application\Deposit\UseCase;

use App\Application\Deposit\Port\DepositTransactionRepositoryInterface;
use App\Application\Deposit\Port\TransactionReceiptReaderInterface;
use App\Application\Deposit\UseCase\RecheckPendingDepositTransactions;
use App\Application\Vault\Exception\BlockchainCallException;
use App\Domain\Deposit\DepositEvent;
use App\Domain\Deposit\DepositTransaction;
use App\Domain\Deposit\DepositTransactionStatus;
use App\Domain\Deposit\TransactionHash;
use App\Domain\Deposit\TransactionReceipt;
use App\Domain\Identity\WalletAddress;
use BcMath\Number;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RecheckPendingDepositTransactionsTest extends TestCase
{
    private const WALLET = '0x2222222222222222222222222222222222222222';

    public function testConfirmsAPendingTransactionOnceItsReceiptIsAvailable(): void
    {
        $wallet = new WalletAddress(self::WALLET);
        $pending = DepositTransaction::pending($wallet, $this->txHash());

        $repository = $this->createMock(DepositTransactionRepositoryInterface::class);
        $repository->method('findAllPending')->willReturn([$pending]);
        $repository->expects(self::once())->method('save')->with($pending);

        $receiptReader = $this->createStub(TransactionReceiptReaderInterface::class);
        $receiptReader->method('getReceipt')->willReturn(new TransactionReceipt(
            success: true,
            depositEvent: new DepositEvent(self::WALLET, self::WALLET, new Number('1000000'), new Number('1000000')),
        ));

        (new RecheckPendingDepositTransactions($repository, $receiptReader, new NullLogger()))();

        self::assertSame(DepositTransactionStatus::Confirmed, $pending->status);
    }

    public function testLeavesATransactionPendingWhenNoReceiptYet(): void
    {
        $pending = DepositTransaction::pending(new WalletAddress(self::WALLET), $this->txHash());

        $repository = $this->createMock(DepositTransactionRepositoryInterface::class);
        $repository->method('findAllPending')->willReturn([$pending]);
        $repository->expects(self::never())->method('save');

        $receiptReader = $this->createStub(TransactionReceiptReaderInterface::class);
        $receiptReader->method('getReceipt')->willReturn(null);

        (new RecheckPendingDepositTransactions($repository, $receiptReader, new NullLogger()))();

        self::assertTrue($pending->isPending());
    }

    public function testOneTransactionsRpcFailureDoesNotAbortTheOthers(): void
    {
        $wallet = new WalletAddress(self::WALLET);
        $failingLookup = DepositTransaction::pending($wallet, $this->txHash());
        $succeedingLookup = DepositTransaction::pending($wallet, new TransactionHash('0x'.str_repeat('b2', 32)));

        $repository = $this->createMock(DepositTransactionRepositoryInterface::class);
        $repository->method('findAllPending')->willReturn([$failingLookup, $succeedingLookup]);
        $repository->expects(self::once())->method('save')->with($succeedingLookup);

        $receiptReader = $this->createStub(TransactionReceiptReaderInterface::class);
        $receiptReader->method('getReceipt')->willReturnCallback(
            static fn (TransactionHash $txHash): TransactionReceipt => $txHash->equals($failingLookup->txHash)
                ? throw BlockchainCallException::rpcError('timeout') : new TransactionReceipt(success: false, depositEvent: null),
        );

        (new RecheckPendingDepositTransactions($repository, $receiptReader, new NullLogger()))();

        self::assertTrue($failingLookup->isPending());
        self::assertSame(DepositTransactionStatus::Failed, $succeedingLookup->status);
    }

    private function txHash(): TransactionHash
    {
        return new TransactionHash('0x'.str_repeat('a1', 32));
    }
}
