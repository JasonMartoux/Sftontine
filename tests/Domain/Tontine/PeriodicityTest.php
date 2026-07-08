<?php

declare(strict_types=1);

namespace App\Tests\Domain\Tontine;

use App\Domain\Tontine\Periodicity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PeriodicityTest extends TestCase
{
    /**
     * @return iterable<string, array{Periodicity, string}>
     */
    public static function provideIntervals(): iterable
    {
        yield 'weekly' => [Periodicity::Weekly, 'P7D'];
        yield 'biweekly' => [Periodicity::Biweekly, 'P14D'];
        yield 'monthly' => [Periodicity::Monthly, 'P1M'];
        yield 'semiannual' => [Periodicity::Semiannual, 'P6M'];
    }

    #[DataProvider('provideIntervals')]
    public function testDateIntervalMatchesPeriod(Periodicity $periodicity, string $expectedSpec): void
    {
        $start = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');

        self::assertEquals(
            $start->add(new \DateInterval($expectedSpec)),
            $start->add($periodicity->dateInterval()),
        );
    }

    public function testDateIntervalThrowsForPunctual(): void
    {
        $this->expectException(\UnhandledMatchError::class);

        Periodicity::Punctual->dateInterval();
    }

    /**
     * @return iterable<string, array{Periodicity}>
     */
    public static function provideRecurringPeriodicities(): iterable
    {
        yield 'weekly' => [Periodicity::Weekly];
        yield 'biweekly' => [Periodicity::Biweekly];
        yield 'monthly' => [Periodicity::Monthly];
        yield 'semiannual' => [Periodicity::Semiannual];
    }

    #[DataProvider('provideRecurringPeriodicities')]
    public function testIsRecurringIsTrueForRecurringPeriodicities(Periodicity $periodicity): void
    {
        self::assertTrue($periodicity->isRecurring());
    }

    public function testIsRecurringIsFalseForPunctual(): void
    {
        self::assertFalse(Periodicity::Punctual->isRecurring());
    }
}
