<?php

declare(strict_types=1);

namespace App\Domain\Tontine\Exception;

final class GroupClosedException extends \LogicException
{
    public static function create(): self
    {
        return new self('This tontine group is closed.');
    }
}
