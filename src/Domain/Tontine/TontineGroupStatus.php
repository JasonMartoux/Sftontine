<?php

declare(strict_types=1);

namespace App\Domain\Tontine;

enum TontineGroupStatus: string
{
    case Active = 'active';
    case Closed = 'closed';
}
