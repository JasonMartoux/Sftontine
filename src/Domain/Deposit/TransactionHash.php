<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

use App\Domain\Deposit\Exception\InvalidTransactionHashException;

final readonly class TransactionHash
{
    private const PATTERN = '/^0x[0-9a-fA-F]{64}$/';

    public function __construct(
        public string $value,
    ) {
        if (1 !== preg_match(self::PATTERN, $this->value)) {
            throw InvalidTransactionHashException::forValue($this->value);
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
