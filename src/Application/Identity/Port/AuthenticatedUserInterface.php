<?php

declare(strict_types=1);

namespace App\Application\Identity\Port;

use App\Domain\Identity\User;

/**
 * Implemented by the framework-specific security user wrapper, so Presentation
 * can access the Domain user without depending on Infrastructure directly.
 */
interface AuthenticatedUserInterface
{
    public function getDomainUser(): User;
}
