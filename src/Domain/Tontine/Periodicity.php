<?php

declare(strict_types=1);

namespace App\Domain\Tontine;

enum Periodicity: string
{
    case Weekly = 'weekly';
    case Biweekly = 'biweekly';
    case Monthly = 'monthly';
    case Semiannual = 'semiannual';
    case Punctual = 'punctual';

    /**
     * False only for Punctual: free contributions, no due-date schedule, never late.
     */
    public function isRecurring(): bool
    {
        return self::Punctual !== $this;
    }

    /**
     * @throws \UnhandledMatchError if called on a non-recurring periodicity (Punctual) —
     *                              every call site must guard with isRecurring() first
     */
    public function dateInterval(): \DateInterval
    {
        return new \DateInterval(match ($this) {
            self::Weekly => 'P7D',
            self::Biweekly => 'P14D',
            self::Monthly => 'P1M',
            self::Semiannual => 'P6M',
        });
    }
}
