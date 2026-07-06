<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

final class InvalidWalletAddressException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(\sprintf('"%s" is not a valid EVM wallet address.', $value));
    }
}
