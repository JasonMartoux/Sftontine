<?php

declare(strict_types=1);

namespace App\Tests\Presentation\Http\Dto;

use App\Presentation\Http\Dto\TontineGroupListItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TontineGroupListItemTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideLabels(): iterable
    {
        yield 'weekly' => ['weekly', 'Hebdomadaire'];
        yield 'biweekly' => ['biweekly', 'Bimensuelle'];
        yield 'monthly' => ['monthly', 'Mensuelle'];
        yield 'semiannual' => ['semiannual', 'Semestrielle'];
        yield 'punctual' => ['punctual', 'Ponctuelle'];
    }

    #[DataProvider('provideLabels')]
    public function testFrenchLabel(string $value, string $expectedLabel): void
    {
        self::assertSame($expectedLabel, TontineGroupListItem::frenchLabel($value));
    }
}
