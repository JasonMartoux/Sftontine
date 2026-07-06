<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Application\Identity\Port\AuthenticatedUserInterface;
use App\Domain\Identity\User;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class SecurityUser implements UserInterface, AuthenticatedUserInterface
{
    public function __construct(
        private User $user,
    ) {
    }

    public function getDomainUser(): User
    {
        return $this->user;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        $subjectId = $this->user->privySubjectId;

        if ('' === $subjectId) {
            throw new \LogicException('User has an empty Privy subject id.');
        }

        return $subjectId;
    }
}
