<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Type;

use App\Domain\Identity\WalletAddress;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;

final class WalletAddressType extends StringType
{
    public const NAME = 'wallet_address';

    public function convertToPHPValue($value, AbstractPlatform $platform): ?WalletAddress
    {
        if (null === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw new \InvalidArgumentException('Expected a string value from the database.');
        }

        return new WalletAddress($value);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof WalletAddress) {
            throw new \InvalidArgumentException('Expected an instance of WalletAddress.');
        }

        return (string) $value;
    }

    public function getName(): string
    {
        return self::NAME;
    }
}
