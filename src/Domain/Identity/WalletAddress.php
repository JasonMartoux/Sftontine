<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Identity\Exception\InvalidWalletAddressException;

final readonly class WalletAddress
{
    private const PATTERN = '/^0x[0-9a-fA-F]{40}$/';

    public function __construct(
        public string $value,
    ) {
        if (1 !== preg_match(self::PATTERN, $this->value)) {
            throw InvalidWalletAddressException::forValue($this->value);
        }
    }

    public function equals(self $other): bool
    {
        return strtolower($this->value) === strtolower($other->value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
