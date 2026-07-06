<?php

declare(strict_types=1);

namespace App\Domain\Deposit\Exception;

final class InvalidTransactionHashException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(\sprintf('"%s" is not a valid transaction hash.', $value));
    }
}
