<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Deposit\Port\DepositTransactionRepositoryInterface;
use App\Domain\Deposit\DepositTransaction;
use App\Domain\Deposit\DepositTransactionStatus;
use App\Domain\Deposit\TransactionHash;
use App\Domain\Identity\WalletAddress;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineDepositTransactionRepository implements DepositTransactionRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(DepositTransaction $depositTransaction): void
    {
        $this->entityManager->persist($depositTransaction);
        $this->entityManager->flush();
    }

    public function findByTxHash(TransactionHash $txHash): ?DepositTransaction
    {
        return $this->entityManager->getRepository(DepositTransaction::class)->findOneBy(['txHash' => $txHash]);
    }

    public function findAllPending(): array
    {
        return $this->entityManager->getRepository(DepositTransaction::class)->findBy(
            ['status' => DepositTransactionStatus::Pending],
        );
    }

    public function findLatestFor(WalletAddress $walletAddress): ?DepositTransaction
    {
        return $this->entityManager->getRepository(DepositTransaction::class)->findOneBy(
            ['walletAddress' => $walletAddress],
            ['createdAt' => 'DESC'],
        );
    }
}
