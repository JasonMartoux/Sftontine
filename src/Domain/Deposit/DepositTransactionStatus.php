<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

enum DepositTransactionStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Failed = 'failed';
}
