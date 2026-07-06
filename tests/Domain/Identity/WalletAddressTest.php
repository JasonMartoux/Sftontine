<?php

declare(strict_types=1);

namespace App\Tests\Domain\Identity;

use App\Domain\Identity\Exception\InvalidWalletAddressException;
use App\Domain\Identity\WalletAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WalletAddressTest extends TestCase
{
    public function testValidAddressIsAccepted(): void
    {
        $address = new WalletAddress('0x8C60503C0353ED12c3Eebc3036BF033A3BbB95Aa');

        self::assertSame('0x8C60503C0353ED12c3Eebc3036BF033A3BbB95Aa', (string) $address);
    }

    public function testEqualsIsCaseInsensitive(): void
    {
        $a = new WalletAddress('0x8C60503C0353ED12c3Eebc3036BF033A3BbB95Aa');
        $b = new WalletAddress('0x8c60503c0353ed12c3eebc3036bf033a3bbb95aa');

        self::assertTrue($a->equals($b));
    }

    #[DataProvider('invalidAddresses')]
    public function testInvalidAddressIsRejected(string $value): void
    {
        $this->expectException(InvalidWalletAddressException::class);

        new WalletAddress($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidAddresses(): iterable
    {
        yield 'missing 0x prefix' => ['8C60503C0353ED12c3Eebc3036BF033A3BbB95Aa'];
        yield 'too short' => ['0x8C60503C0353ED12c3Eebc3036BF033A3BbB95'];
        yield 'too long' => ['0x8C60503C0353ED12c3Eebc3036BF033A3BbB95AaFF'];
        yield 'non-hex characters' => ['0xZZ60503C0353ED12c3Eebc3036BF033A3BbB95Aa'];
        yield 'empty string' => [''];
    }
}
