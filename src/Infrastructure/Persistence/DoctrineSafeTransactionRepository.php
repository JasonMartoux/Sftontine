<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Safe\Port\SafeTransactionRepositoryInterface;
use App\Domain\Deposit\TransactionHash;
use App\Domain\Safe\SafeTransaction;
use App\Domain\Safe\SafeTransactionPurpose;
use App\Domain\Safe\SafeTransactionStatus;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineSafeTransactionRepository implements SafeTransactionRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(SafeTransaction $transaction): void
    {
        $this->entityManager->persist($transaction);
        $this->entityManager->flush();
    }

    public function findByTxHash(TransactionHash $txHash): ?SafeTransaction
    {
        return $this->entityManager->getRepository(SafeTransaction::class)->findOneBy(['txHash' => $txHash]);
    }

    public function findAllPending(): array
    {
        return $this->entityManager->getRepository(SafeTransaction::class)->findBy(
            ['status' => SafeTransactionStatus::Pending],
        );
    }

    public function findLatestForGroup(int $groupId, SafeTransactionPurpose $purpose): ?SafeTransaction
    {
        return $this->entityManager->getRepository(SafeTransaction::class)->findOneBy(
            ['groupId' => $groupId, 'purpose' => $purpose],
            ['createdAt' => 'DESC'],
        );
    }
}
