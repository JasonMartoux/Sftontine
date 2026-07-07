<?php

declare(strict_types=1);

namespace App\Domain\Tontine\Exception;

final class NotAMemberException extends \LogicException
{
    public static function forUserId(?int $userId): self
    {
        return new self(\sprintf('User "%s" is not a member of this tontine group.', $userId ?? 'unknown'));
    }
}
