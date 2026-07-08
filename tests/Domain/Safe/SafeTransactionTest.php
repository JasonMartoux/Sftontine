<?php

declare(strict_types=1);

namespace App\Tests\Domain\Safe;

use App\Domain\Deposit\TransactionHash;
use App\Domain\Identity\WalletAddress;
use App\Domain\Safe\SafeTransaction;
use App\Domain\Safe\SafeTransactionPurpose;
use App\Domain\Safe\SafeTransactionStatus;
use PHPUnit\Framework\TestCase;

final class SafeTransactionTest extends TestCase
{
    private const SAFE_ADDRESS = '0x4444444444444444444444444444444444444444';

    public function testPendingDeploymentStartsPendingWithNoSafeAddress(): void
    {
        $tx = SafeTransaction::pendingDeployment(1, $this->txHash('a1'));

        self::assertTrue($tx->isPending());
        self::assertSame(SafeTransactionStatus::Pending, $tx->status);
        self::assertSame(SafeTransactionPurpose::Deployment, $tx->purpose);
        self::assertNull($tx->safeAddress);
        self::assertNull($tx->safeTxHash);
    }

    public function testSuccessfulDeploymentReceiptConfirmsAndRecordsSafeAddress(): void
    {
        $tx = SafeTransaction::pendingDeployment(1, $this->txHash('a1'));

        $tx->applyDeploymentReceipt(true, new WalletAddress(self::SAFE_ADDRESS));

        self::assertSame(SafeTransactionStatus::Confirmed, $tx->status);
        self::assertNotNull($tx->safeAddress);
        self::assertSame(self::SAFE_ADDRESS, $tx->safeAddress->value);
    }

    public function testFailedDeploymentReceiptIsMarkedFailed(): void
    {
        $tx = SafeTransaction::pendingDeployment(1, $this->txHash('a1'));

        $tx->applyDeploymentReceipt(false, null);

        self::assertSame(SafeTransactionStatus::Failed, $tx->status);
        self::assertNull($tx->safeAddress);
    }

    public function testSuccessfulDeploymentWithoutAnAddressIsMarkedFailed(): void
    {
        $tx = SafeTransaction::pendingDeployment(1, $this->txHash('a1'));

        $tx->applyDeploymentReceipt(true, null);

        self::assertSame(SafeTransactionStatus::Failed, $tx->status);
    }

    public function testPendingExecutionStartsPendingWithSafeTxHash(): void
    {
        $tx = SafeTransaction::pendingExecution(1, SafeTransactionPurpose::ConnectYieldPool, $this->txHash('a1'), $this->txHash('b2'));

        self::assertTrue($tx->isPending());
        self::assertSame(SafeTransactionPurpose::ConnectYieldPool, $tx->purpose);
        self::assertNotNull($tx->safeTxHash);
        self::assertSame('0x'.str_repeat('b2', 32), $tx->safeTxHash->value);
    }

    public function testSuccessfulExecutionReceiptConfirms(): void
    {
        $tx = SafeTransaction::pendingExecution(1, SafeTransactionPurpose::ConnectYieldPool, $this->txHash('a1'), $this->txHash('b2'));

        $tx->applyExecutionReceipt(true);

        self::assertSame(SafeTransactionStatus::Confirmed, $tx->status);
    }

    public function testFailedExecutionReceiptIsMarkedFailed(): void
    {
        $tx = SafeTransaction::pendingExecution(1, SafeTransactionPurpose::ConnectYieldPool, $this->txHash('a1'), $this->txHash('b2'));

        $tx->applyExecutionReceipt(false);

        self::assertSame(SafeTransactionStatus::Failed, $tx->status);
    }

    private function txHash(string $pair): TransactionHash
    {
        return new TransactionHash('0x'.str_repeat($pair, 32));
    }
}
