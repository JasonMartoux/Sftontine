<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Identity\Port\UserRepositoryInterface;
use App\Domain\Identity\User;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineUserRepository implements UserRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findByPrivySubjectId(string $subjectId): ?User
    {
        return $this->entityManager->getRepository(User::class)->findOneBy(['privySubjectId' => $subjectId]);
    }

    public function save(User $user): void
    {
        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }
}
