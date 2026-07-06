<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Privy;

use App\Infrastructure\Privy\Eip55AddressChecksum;
use PHPUnit\Framework\TestCase;

final class Eip55AddressChecksumTest extends TestCase
{
    private const CHECKSUMMED = '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed';

    public function testCorrectlyChecksummedAddressIsValid(): void
    {
        self::assertTrue(Eip55AddressChecksum::isValid(self::CHECKSUMMED));
    }

    public function testAllLowercaseAddressIsValid(): void
    {
        self::assertTrue(Eip55AddressChecksum::isValid(strtolower(self::CHECKSUMMED)));
    }

    public function testAllUppercaseAddressIsValid(): void
    {
        $allUppercaseHex = '0x'.strtoupper(substr(self::CHECKSUMMED, 2));

        self::assertTrue(Eip55AddressChecksum::isValid($allUppercaseHex));
    }

    public function testWrongCaseChecksumIsRejected(): void
    {
        // Flip the case of every character in the correctly checksummed address:
        // still valid hex, still valid format, but the checksum no longer matches.
        $mixedWrong = '0x'.strtr(
            substr(self::CHECKSUMMED, 2),
            array_combine(
                str_split('0123456789abcdefABCDEF'),
                str_split('0123456789ABCDEFabcdef'),
            ),
        );

        self::assertFalse(Eip55AddressChecksum::isValid($mixedWrong));
    }

    public function testInvalidFormatIsRejected(): void
    {
        self::assertFalse(Eip55AddressChecksum::isValid('not-an-address'));
        self::assertFalse(Eip55AddressChecksum::isValid('0x123'));
    }
}
