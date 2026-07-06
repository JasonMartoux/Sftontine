<?php

declare(strict_types=1);

namespace App\Infrastructure\Mercure;

use App\Application\Deposit\Port\DepositTransactionRepositoryInterface;
use App\Domain\Deposit\DepositTransaction;
use App\Domain\Deposit\TransactionHash;
use App\Domain\Identity\WalletAddress;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Twig\Environment;

/**
 * Decorates the Doctrine repository so every status change (pending -> confirmed/failed)
 * pushes a Turbo Stream update over Mercure — single source of truth, no use case can
 * forget to notify the frontend.
 */
final readonly class DepositTransactionStatusPublisher implements DepositTransactionRepositoryInterface
{
    public function __construct(
        private DepositTransactionRepositoryInterface $inner,
        private HubInterface $hub,
        private Environment $twig,
    ) {
    }

    public function save(DepositTransaction $depositTransaction): void
    {
        $this->inner->save($depositTransaction);

        $html = $this->twig->render('deposit/_transaction_status.stream.html.twig', [
            'deposit_transaction' => $depositTransaction,
        ]);

        $this->hub->publish(new Update(
            topics: 'deposit_transaction/'.$depositTransaction->walletAddress,
            data: $html,
        ));
    }

    public function findByTxHash(TransactionHash $txHash): ?DepositTransaction
    {
        return $this->inner->findByTxHash($txHash);
    }

    public function findAllPending(): array
    {
        return $this->inner->findAllPending();
    }

    public function findLatestFor(WalletAddress $walletAddress): ?DepositTransaction
    {
        return $this->inner->findLatestFor($walletAddress);
    }
}
