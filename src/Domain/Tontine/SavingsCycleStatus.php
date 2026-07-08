<?php

declare(strict_types=1);

namespace App\Domain\Tontine;

enum SavingsCycleStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
