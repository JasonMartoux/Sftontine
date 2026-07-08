<?php

declare(strict_types=1);

namespace App\Tests\Application\Shared;

use App\Application\Shared\Result;
use PHPUnit\Framework\TestCase;

enum FakeError: string
{
    case Broken = 'broken';
}

final class ResultTest extends TestCase
{
    public function testSuccessCarriesTheValue(): void
    {
        $result = Result::success('the-value');

        self::assertTrue($result->isSuccess);
        self::assertSame('the-value', $result->value());
    }

    public function testSuccessDefaultsToNullValue(): void
    {
        $result = Result::success();

        self::assertTrue($result->isSuccess);
        self::assertNull($result->value());
    }

    public function testFailureCarriesTheError(): void
    {
        $result = Result::failure(FakeError::Broken);

        self::assertFalse($result->isSuccess);
        self::assertSame(FakeError::Broken, $result->error());
    }

    public function testValueThrowsOnFailure(): void
    {
        $result = Result::failure(FakeError::Broken);

        $this->expectException(\LogicException::class);

        $result->value();
    }

    public function testErrorThrowsOnSuccess(): void
    {
        $result = Result::success('the-value');

        $this->expectException(\LogicException::class);

        $result->error();
    }
}
