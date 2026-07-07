<?php

declare(strict_types=1);

namespace App\Domain\Tontine\Exception;

final class AlreadyMemberException extends \LogicException
{
    public static function forUserId(?int $userId): self
    {
        return new self(\sprintf('User "%s" is already a member of this tontine group.', $userId ?? 'unknown'));
    }
}
