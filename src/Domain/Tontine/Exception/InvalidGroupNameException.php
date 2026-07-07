<?php

declare(strict_types=1);

namespace App\Domain\Tontine\Exception;

final class InvalidGroupNameException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(\sprintf('"%s" is not a valid tontine group name (3 to 100 characters).', $value));
    }
}
