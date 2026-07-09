<?php

declare(strict_types=1);

namespace App\Tests\Application\Safe\UseCase;

use App\Application\Safe\Dto\SafeExecutionReceipt;
use App\Application\Safe\Port\SafeReceiptReaderInterface;
use App\Application\Safe\Port\SafeTransactionRepositoryInterface;
use App\Application\Safe\UseCase\ConfirmGroupSafeExecution;
use App\Application\Safe\UseCase\GroupSafeError;
use App\Application\Tontine\Port\TontineGroupRepositoryInterface;
use App\Domain\Deposit\TransactionHash;
use App\Domain\Identity\User;
use App\Domain\Identity\WalletAddress;
use App\Domain\Safe\SafeTransactionPurpose;
use App\Domain\Safe\SafeTransactionStatus;
use App\Domain\Tontine\Periodicity;
use App\Domain\Tontine\TontineGroup;
use Money\Currency;
use Money\Money;
use PHPUnit\Framework\TestCase;

final class ConfirmGroupSafeExecutionTest extends TestCase
{
    private const ADMIN_WALLET = '0x1111111111111111111111111111111111111111';

    // IMPORTANT: reuse the same $admin instance across a test (store it, don't call a
    // fresh User::registerFromPrivy(...) more than once) — Membership::isFor() falls
    // back to object identity (===) when both users are unpersisted (id === null), so
    // two separately-constructed "admin" User objects with identical data are NOT
    // recognized as the same member and membershipFor() would wrongly return null.
    // Task 6 hit exactly this bug; avoid it here by building $admin once per test.
    private User $admin;

    protected function setUp(): void
    {
        $this->admin = User::registerFromPrivy('did:privy:admin', 'admin@example.com', new WalletAddress(self::ADMIN_WALLET));
    }

    public function testReturnsGroupNotFound(): void
    {
        $groups = $this->createStub(TontineGroupRepositoryInterface::class);
        $groups->method('find')->willReturn(null);

        $useCase = new ConfirmGroupSafeExecution($groups, $this->createStub(SafeTransactionRepositoryInterface::class), $this->createStub(SafeReceiptReaderInterface::class));
        $result = $useCase($this->admin, 1, SafeTransactionPurpose::ConnectYieldPool, $this->txHash('a1'), $this->txHash('b2'));

        self::assertFalse($result->isSuccess);
        self::assertSame(GroupSafeError::GroupNotFound, $result->error());
    }

    public function testConfirmsExecution(): void
    {
        $group = $this->makeGroup();

        $groups = $this->createStub(TontineGroupRepositoryInterface::class);
        $groups->method('find')->willReturn($group);

        $safeTransactions = $this->createMock(SafeTransactionRepositoryInterface::class);
        $safeTransactions->method('findByTxHash')->willReturn(null);
        $safeTransactions->expects(self::once())->method('save');

        $receiptReader = $this->createStub(SafeReceiptReaderInterface::class);
        $receiptReader->method('getExecutionReceipt')->willReturn(new SafeExecutionReceipt(true));

        $useCase = new ConfirmGroupSafeExecution($groups, $safeTransactions, $receiptReader);
        $result = $useCase($this->admin, 1, SafeTransactionPurpose::ConnectYieldPool, $this->txHash('a1'), $this->txHash('b2'));

        self::assertTrue($result->isSuccess);
        self::assertSame(SafeTransactionStatus::Confirmed->value, $result->value()->status);
    }

    public function testFailedExecutionIsReportedAsFailed(): void
    {
        $group = $this->makeGroup();

        $groups = $this->createStub(TontineGroupRepositoryInterface::class);
        $groups->method('find')->willReturn($group);

        $safeTransactions = $this->createMock(SafeTransactionRepositoryInterface::class);
        $safeTransactions->method('findByTxHash')->willReturn(null);
        $safeTransactions->expects(self::once())->method('save');

        $receiptReader = $this->createStub(SafeReceiptReaderInterface::class);
        $receiptReader->method('getExecutionReceipt')->willReturn(new SafeExecutionReceipt(false));

        $useCase = new ConfirmGroupSafeExecution($groups, $safeTransactions, $receiptReader);
        $result = $useCase($this->admin, 1, SafeTransactionPurpose::ConnectYieldPool, $this->txHash('a1'), $this->txHash('b2'));

        self::assertTrue($result->isSuccess);
        self::assertSame(SafeTransactionStatus::Failed->value, $result->value()->status);
    }

    private function makeGroup(): TontineGroup
    {
        return TontineGroup::create($this->admin, 'Tontine famille', new Money('25000000', new Currency('USDC')), Periodicity::Weekly, 12, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    }

    private function txHash(string $pair): TransactionHash
    {
        return new TransactionHash('0x'.str_repeat($pair, 32));
    }
}
