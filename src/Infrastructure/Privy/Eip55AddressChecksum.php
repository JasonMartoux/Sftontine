<?php

declare(strict_types=1);

namespace App\Infrastructure\Privy;

use kornrunner\Keccak;

final class Eip55AddressChecksum
{
    private const FORMAT = '/^0x[0-9a-fA-F]{40}$/';

    public static function isValid(string $address): bool
    {
        if (1 !== preg_match(self::FORMAT, $address)) {
            return false;
        }

        $hex = substr($address, 2);
        $lower = strtolower($hex);

        // All-lowercase or all-uppercase addresses are valid but "unchecksummed".
        if ($hex === $lower || $hex === strtoupper($hex)) {
            return true;
        }

        return $hex === self::checksum($lower);
    }

    private static function checksum(string $lowercaseHex): string
    {
        $hash = Keccak::hash($lowercaseHex, 256);

        $result = '';
        for ($i = 0; $i < \strlen($lowercaseHex); ++$i) {
            $result .= hexdec($hash[$i]) >= 8
                ? strtoupper($lowercaseHex[$i])
                : $lowercaseHex[$i];
        }

        return $result;
    }
}
