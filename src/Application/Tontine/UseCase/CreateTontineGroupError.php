<?php

declare(strict_types=1);

namespace App\Application\Tontine\UseCase;

enum CreateTontineGroupError: string
{
    case InvalidName = 'invalid_name';
    case InvalidAmount = 'invalid_amount';
    case InvalidPeriodicity = 'invalid_periodicity';
    case InvalidCycleLength = 'invalid_cycle_length';
}
