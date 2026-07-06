<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Vault\Port\YieldSnapshotRepositoryInterface;
use App\Domain\Identity\WalletAddress;
use App\Domain\Vault\YieldSnapshot;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineYieldSnapshotRepository implements YieldSnapshotRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(YieldSnapshot $snapshot): void
    {
        $this->entityManager->persist($snapshot);
        $this->entityManager->flush();
    }

    public function findLatestFor(WalletAddress $walletAddress): ?YieldSnapshot
    {
        return $this->entityManager->getRepository(YieldSnapshot::class)->findOneBy(
            ['walletAddress' => $walletAddress],
            ['capturedAt' => 'DESC'],
        );
    }

    public function findMostRecent(): ?YieldSnapshot
    {
        return $this->entityManager->getRepository(YieldSnapshot::class)->findOneBy(
            [],
            ['capturedAt' => 'DESC'],
        );
    }
}
