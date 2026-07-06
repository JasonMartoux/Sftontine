<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Mercure;

use App\Application\Deposit\Port\DepositTransactionRepositoryInterface;
use App\Domain\Deposit\DepositTransaction;
use App\Domain\Deposit\TransactionHash;
use App\Domain\Identity\WalletAddress;
use App\Infrastructure\Mercure\DepositTransactionStatusPublisher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Twig\Environment;

final class DepositTransactionStatusPublisherTest extends TestCase
{
    private const WALLET = '0x2222222222222222222222222222222222222222';

    public function testSavingDelegatesThenPublishesAnUpdateOnTheWalletsTopic(): void
    {
        $depositTransaction = DepositTransaction::pending(
            new WalletAddress(self::WALLET),
            new TransactionHash('0x'.str_repeat('a1', 32)),
        );

        $inner = $this->createMock(DepositTransactionRepositoryInterface::class);
        $inner->expects(self::once())->method('save')->with($depositTransaction);

        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('<turbo-stream></turbo-stream>');

        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())->method('publish')->with(self::callback(
            static function (Update $update) use ($depositTransaction): bool {
                self::assertSame(['deposit_transaction/'.$depositTransaction->walletAddress], $update->getTopics());

                return true;
            },
        ));

        $publisher = new DepositTransactionStatusPublisher($inner, $hub, $twig);
        $publisher->save($depositTransaction);
    }

    public function testReadMethodsDelegateWithoutPublishing(): void
    {
        $txHash = new TransactionHash('0x'.str_repeat('a1', 32));

        $inner = $this->createMock(DepositTransactionRepositoryInterface::class);
        $inner->expects(self::once())->method('findByTxHash')->with($txHash)->willReturn(null);
        $inner->expects(self::once())->method('findAllPending')->willReturn([]);
        $inner->expects(self::once())->method('findLatestFor')->willReturn(null);

        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::never())->method('publish');

        $publisher = new DepositTransactionStatusPublisher($inner, $hub, $this->createStub(Environment::class));

        $publisher->findByTxHash($txHash);
        $publisher->findAllPending();
        $publisher->findLatestFor(new WalletAddress(self::WALLET));
    }
}
