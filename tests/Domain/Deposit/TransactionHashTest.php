<?php

declare(strict_types=1);

namespace App\Tests\Domain\Deposit;

use App\Domain\Deposit\Exception\InvalidTransactionHashException;
use App\Domain\Deposit\TransactionHash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransactionHashTest extends TestCase
{
    public function testValidHashIsAccepted(): void
    {
        $value = '0x'.str_repeat('a1', 32);
        $hash = new TransactionHash($value);

        self::assertSame($value, (string) $hash);
    }

    public function testEqualsIsCaseInsensitive(): void
    {
        $a = new TransactionHash('0x'.str_repeat('AB', 32));
        $b = new TransactionHash('0x'.str_repeat('ab', 32));

        self::assertTrue($a->equals($b));
    }

    #[DataProvider('invalidHashes')]
    public function testInvalidHashIsRejected(string $value): void
    {
        $this->expectException(InvalidTransactionHashException::class);

        new TransactionHash($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidHashes(): iterable
    {
        yield 'missing 0x prefix' => [str_repeat('a', 64)];
        yield 'too short' => ['0x'.str_repeat('a', 63)];
        yield 'too long' => ['0x'.str_repeat('a', 65)];
        yield 'non-hex characters' => ['0x'.str_repeat('z', 64)];
        yield 'empty string' => [''];
    }
}
