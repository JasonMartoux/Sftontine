<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Tontine\Port\TontineGroupRepositoryInterface;
use App\Domain\Tontine\Contribution;
use App\Domain\Tontine\Membership;
use App\Domain\Tontine\SavingsCycle;
use App\Domain\Tontine\TontineGroup;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineTontineGroupRepository implements TontineGroupRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(TontineGroup $group): void
    {
        $this->entityManager->persist($group);
        foreach ($group->memberships() as $membership) {
            $this->entityManager->persist($membership);
        }
        foreach ($group->cycles() as $cycle) {
            $this->entityManager->persist($cycle);
        }
        foreach ($group->contributions() as $contribution) {
            $this->entityManager->persist($contribution);
        }
        $this->entityManager->flush();
    }

    public function find(int $id): ?TontineGroup
    {
        $group = $this->entityManager->find(TontineGroup::class, $id);
        if (null === $group) {
            return null;
        }

        // A persisted group always has at least its creator's membership, so an empty
        // array reliably means "not yet hydrated" — avoids attaching twice when the
        // identity map returns the same already-hydrated instance.
        if ([] === $group->memberships()) {
            $group->attachPersistedChildren(
                $this->entityManager->getRepository(Membership::class)->findBy(['group' => $group]),
                $this->entityManager->getRepository(SavingsCycle::class)->findBy(['group' => $group]),
                $this->entityManager->getRepository(Contribution::class)->findBy(['group' => $group]),
            );
        }

        return $group;
    }

    public function findAllForUserId(int $userId): array
    {
        $groupIds = $this->entityManager->getRepository(Membership::class)->createQueryBuilder('m')
            ->select('IDENTITY(m.group)')
            ->where('m.user = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleColumnResult();

        $groups = [];
        foreach ($groupIds as $groupId) {
            \assert(is_numeric($groupId));
            $group = $this->find((int) $groupId);
            if (null !== $group) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    public function findAllWithSafeAddress(): array
    {
        $ids = $this->entityManager->createQueryBuilder()
            ->select('g.id')
            ->from(TontineGroup::class, 'g')
            ->where('g.safeAddress IS NOT NULL')
            ->getQuery()
            ->getSingleColumnResult();

        $groups = [];
        foreach ($ids as $id) {
            \assert(is_numeric($id));
            $group = $this->find((int) $id);
            if (null !== $group) {
                $groups[] = $group;
            }
        }

        return $groups;
    }
}
