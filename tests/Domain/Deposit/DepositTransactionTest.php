<?php

declare(strict_types=1);

namespace App\Tests\Domain\Deposit;

use App\Domain\Deposit\DepositEvent;
use App\Domain\Deposit\DepositTransaction;
use App\Domain\Deposit\DepositTransactionStatus;
use App\Domain\Deposit\TransactionHash;
use App\Domain\Deposit\TransactionReceipt;
use App\Domain\Identity\WalletAddress;
use BcMath\Number;
use PHPUnit\Framework\TestCase;

final class DepositTransactionTest extends TestCase
{
    private const WALLET = '0x2222222222222222222222222222222222222222';

    public function testStartsPending(): void
    {
        $tx = $this->makePending();

        self::assertTrue($tx->isPending());
        self::assertSame(DepositTransactionStatus::Pending, $tx->status);
        self::assertNull($tx->amount());
    }

    public function testSuccessfulReceiptWithMatchingOwnerConfirmsAndRecordsAmount(): void
    {
        $tx = $this->makePending();

        $tx->applyReceipt(new TransactionReceipt(
            success: true,
            depositEvent: new DepositEvent(
                sender: self::WALLET,
                owner: self::WALLET,
                assets: new Number('1000000'),
                shares: new Number('1000000'),
            ),
        ));

        self::assertSame(DepositTransactionStatus::Confirmed, $tx->status);
        $amount = $tx->amount();
        self::assertNotNull($amount);
        self::assertSame('1000000', $amount->getAmount());
        self::assertSame('USDC', $amount->getCurrency()->getCode());
        self::assertSame('1.000000', $tx->amountDisplay());
    }

    public function testFailedTransactionIsMarkedFailed(): void
    {
        $tx = $this->makePending();

        $tx->applyReceipt(new TransactionReceipt(success: false, depositEvent: null));

        self::assertSame(DepositTransactionStatus::Failed, $tx->status);
        self::assertNull($tx->amount());
    }

    public function testSuccessfulReceiptWithMissingDepositEventIsMarkedFailed(): void
    {
        $tx = $this->makePending();

        $tx->applyReceipt(new TransactionReceipt(success: true, depositEvent: null));

        self::assertSame(DepositTransactionStatus::Failed, $tx->status);
    }

    public function testSuccessfulReceiptForADifferentOwnerIsMarkedFailed(): void
    {
        $tx = $this->makePending();

        $tx->applyReceipt(new TransactionReceipt(
            success: true,
            depositEvent: new DepositEvent(
                sender: self::WALLET,
                owner: '0x3333333333333333333333333333333333333333',
                assets: new Number('1000000'),
                shares: new Number('1000000'),
            ),
        ));

        self::assertSame(DepositTransactionStatus::Failed, $tx->status);
    }

    private function makePending(): DepositTransaction
    {
        return DepositTransaction::pending(new WalletAddress(self::WALLET), new TransactionHash('0x'.str_repeat('a1', 32)));
    }
}
