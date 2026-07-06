<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Type;

use App\Domain\Deposit\TransactionHash;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;

final class TransactionHashType extends StringType
{
    public const NAME = 'transaction_hash';

    public function convertToPHPValue($value, AbstractPlatform $platform): ?TransactionHash
    {
        if (null === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw new \InvalidArgumentException('Expected a string value from the database.');
        }

        return new TransactionHash($value);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof TransactionHash) {
            throw new \InvalidArgumentException('Expected an instance of TransactionHash.');
        }

        return (string) $value;
    }

    public function getName(): string
    {
        return self::NAME;
    }
}
