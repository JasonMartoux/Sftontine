<?php

declare(strict_types=1);

namespace App\Tests\Application\Safe\UseCase;

use App\Application\Safe\Dto\SafeDeploymentReceipt;
use App\Application\Safe\Port\SafeReceiptReaderInterface;
use App\Application\Safe\Port\SafeTransactionRepositoryInterface;
use App\Application\Safe\UseCase\ConfirmGroupSafeDeployment;
use App\Application\Safe\UseCase\GroupSafeError;
use App\Application\Tontine\Port\TontineGroupRepositoryInterface;
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

final class ConfirmGroupSafeDeploymentTest extends TestCase
{
    private const ADMIN_WALLET = '0x1111111111111111111111111111111111111111';
    private const MEMBER_WALLET = '0x2222222222222222222222222222222222222222';
    private const SAFE_ADDRESS = '0x4444444444444444444444444444444444444444';

    private User $admin;
    private User $member;

    protected function setUp(): void
    {
        $this->admin = User::registerFromPrivy('did:privy:admin', 'admin@example.com', new WalletAddress(self::ADMIN_WALLET));
        $this->member = User::registerFromPrivy('did:privy:member', 'member@example.com', new WalletAddress(self::MEMBER_WALLET));
    }

    public function testReturnsGroupNotFound(): void
    {
        $groups = $this->createStub(TontineGroupRepositoryInterface::class);
        $groups->method('find')->willReturn(null);

        $useCase = new ConfirmGroupSafeDeployment($groups, $this->createStub(SafeTransactionRepositoryInterface::class), $this->createStub(SafeReceiptReaderInterface::class));
        $result = $useCase($this->admin, 1, $this->txHash());

        self::assertFalse($result->isSuccess);
        self::assertSame(GroupSafeError::GroupNotFound, $result->error());
    }

    public function testReturnsNotAnAdminForARegularMember(): void
    {
        $group = $this->makeGroup();
        $group->join($this->member, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        $groups = $this->createStub(TontineGroupRepositoryInterface::class);
        $groups->method('find')->willReturn($group);

        $useCase = new ConfirmGroupSafeDeployment($groups, $this->createStub(SafeTransactionRepositoryInterface::class), $this->createStub(SafeReceiptReaderInterface::class));
        $result = $useCase($this->member, 1, $this->txHash());

        self::assertFalse($result->isSuccess);
        self::assertSame(GroupSafeError::NotAnAdmin, $result->error());
    }

    public function testConfirmsDeploymentAndProvisionsTheGroupsSafe(): void
    {
        $group = $this->makeGroup();

        $groups = $this->createMock(TontineGroupRepositoryInterface::class);
        $groups->method('find')->willReturn($group);
        $groups->expects(self::once())->method('save')->with($group);

        $safeTransactions = $this->createMock(SafeTransactionRepositoryInterface::class);
        $safeTransactions->method('findByTxHash')->willReturn(null);
        $safeTransactions->expects(self::once())->method('save');

        $receiptReader = $this->createStub(SafeReceiptReaderInterface::class);
        $receiptReader->method('getDeploymentReceipt')->willReturn(new SafeDeploymentReceipt(true, self::SAFE_ADDRESS));

        $useCase = new ConfirmGroupSafeDeployment($groups, $safeTransactions, $receiptReader);
        $result = $useCase($this->admin, 1, $this->txHash());

        self::assertTrue($result->isSuccess);
        self::assertSame(self::SAFE_ADDRESS, $result->value()->safeAddress);
        self::assertSame(SafeTransactionStatus::Confirmed->value, $result->value()->status);
        self::assertTrue($group->hasSafe());
    }

    public function testStaysPendingWhenReceiptNotYetAvailable(): void
    {
        $group = $this->makeGroup();

        $groups = $this->createMock(TontineGroupRepositoryInterface::class);
        $groups->method('find')->willReturn($group);
        $groups->expects(self::never())->method('save');

        $safeTransactions = $this->createMock(SafeTransactionRepositoryInterface::class);
        $safeTransactions->method('findByTxHash')->willReturn(null);
        $safeTransactions->expects(self::once())->method('save');

        $receiptReader = $this->createStub(SafeReceiptReaderInterface::class);
        $receiptReader->method('getDeploymentReceipt')->willReturn(null);

        $useCase = new ConfirmGroupSafeDeployment($groups, $safeTransactions, $receiptReader);
        $result = $useCase($this->admin, 1, $this->txHash());

        self::assertTrue($result->isSuccess);
        self::assertSame(SafeTransactionStatus::Pending->value, $result->value()->status);
        self::assertFalse($group->hasSafe());
    }

    private function makeGroup(): TontineGroup
    {
        return TontineGroup::create($this->admin, 'Tontine famille', new Money('25000000', new Currency('USDC')), Periodicity::Weekly, 12, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    }

    private function txHash(): TransactionHash
    {
        return new TransactionHash('0x'.str_repeat('a1', 32));
    }
}
