<?php

declare(strict_types=1);

namespace App\Tests\Application\Deposit\UseCase;

use App\Application\Deposit\Port\DepositTransactionRepositoryInterface;
use App\Application\Deposit\Port\TransactionReceiptReaderInterface;
use App\Application\Deposit\UseCase\ConfirmDepositTransaction;
use App\Domain\Deposit\DepositEvent;
use App\Domain\Deposit\DepositTransaction;
use App\Domain\Deposit\DepositTransactionStatus;
use App\Domain\Deposit\TransactionHash;
use App\Domain\Deposit\TransactionReceipt;
use App\Domain\Identity\WalletAddress;
use BcMath\Number;
use PHPUnit\Framework\TestCase;

final class ConfirmDepositTransactionTest extends TestCase
{
    private const WALLET = '0x2222222222222222222222222222222222222222';

    public function testConfirmsWhenReceiptIsAlreadyAvailable(): void
    {
        $txHash = $this->txHash();
        $receiptReader = $this->createStub(TransactionReceiptReaderInterface::class);
        $receiptReader->method('getReceipt')->willReturn(new TransactionReceipt(
            success: true,
            depositEvent: new DepositEvent(self::WALLET, self::WALLET, new Number('1000000'), new Number('1000000')),
        ));

        $repository = $this->createMock(DepositTransactionRepositoryInterface::class);
        $repository->method('findByTxHash')->willReturn(null);
        $repository->expects(self::once())->method('save');

        $useCase = new ConfirmDepositTransaction($receiptReader, $repository);
        $depositTransaction = $useCase(new WalletAddress(self::WALLET), $txHash);

        self::assertSame(DepositTransactionStatus::Confirmed, $depositTransaction->status);
    }

    public function testStaysPendingWhenReceiptIsNotYetAvailable(): void
    {
        $txHash = $this->txHash();
        $receiptReader = $this->createStub(TransactionReceiptReaderInterface::class);
        $receiptReader->method('getReceipt')->willReturn(null);

        $repository = $this->createMock(DepositTransactionRepositoryInterface::class);
        $repository->method('findByTxHash')->willReturn(null);
        $repository->expects(self::once())->method('save');

        $useCase = new ConfirmDepositTransaction($receiptReader, $repository);
        $depositTransaction = $useCase(new WalletAddress(self::WALLET), $txHash);

        self::assertTrue($depositTransaction->isPending());
    }

    public function testReusesExistingTransactionRecordForTheSameTxHash(): void
    {
        $txHash = $this->txHash();
        $existing = DepositTransaction::pending(new WalletAddress(self::WALLET), $txHash);

        $receiptReader = $this->createStub(TransactionReceiptReaderInterface::class);
        $receiptReader->method('getReceipt')->willReturn(null);

        $repository = $this->createMock(DepositTransactionRepositoryInterface::class);
        $repository->method('findByTxHash')->willReturn($existing);
        $repository->expects(self::once())->method('save')->with($existing);

        $useCase = new ConfirmDepositTransaction($receiptReader, $repository);
        $depositTransaction = $useCase(new WalletAddress(self::WALLET), $txHash);

        self::assertSame($existing, $depositTransaction);
    }

    private function txHash(): TransactionHash
    {
        return new TransactionHash('0x'.str_repeat('a1', 32));
    }
}
