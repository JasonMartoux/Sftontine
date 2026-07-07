<?php

declare(strict_types=1);

namespace App\Domain\Tontine;

enum Periodicity: string
{
    case Weekly = 'weekly';
    case Biweekly = 'biweekly';
    case Monthly = 'monthly';

    public function dateInterval(): \DateInterval
    {
        return new \DateInterval(match ($this) {
            self::Weekly => 'P7D',
            self::Biweekly => 'P14D',
            self::Monthly => 'P1M',
        });
    }
}
