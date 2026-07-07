<?php

declare(strict_types=1);

namespace App\Domain\Tontine;

use App\Domain\Identity\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tontine_membership')]
#[ORM\UniqueConstraint(name: 'uniq_tontine_membership_group_user', columns: ['group_id', 'user_id'])]
final class Membership
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    public private(set) ?int $id = null;

    private function __construct(
        #[ORM\ManyToOne(targetEntity: TontineGroup::class)]
        #[ORM\JoinColumn(name: 'group_id', nullable: false)]
        public readonly TontineGroup $group,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false)]
        public readonly User $user,
        #[ORM\Column(name: 'role', type: 'string', length: 16, enumType: MembershipRole::class)]
        public readonly MembershipRole $role,
        #[ORM\Column(name: 'joined_at', type: 'datetime_immutable')]
        public readonly \DateTimeImmutable $joinedAt,
    ) {
    }

    public static function admin(TontineGroup $group, User $user, \DateTimeImmutable $joinedAt): self
    {
        return new self($group, $user, MembershipRole::Admin, $joinedAt);
    }

    public static function member(TontineGroup $group, User $user, \DateTimeImmutable $joinedAt): self
    {
        return new self($group, $user, MembershipRole::Member, $joinedAt);
    }

    public function isFor(User $user): bool
    {
        return $this->user === $user || (null !== $this->user->id && $this->user->id === $user->id);
    }
}
