<?php

declare(strict_types=1);

namespace App\Domain\Safe;

enum SafeTransactionStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Failed = 'failed';
}
