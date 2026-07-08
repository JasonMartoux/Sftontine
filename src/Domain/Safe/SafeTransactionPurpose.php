<?php

declare(strict_types=1);

namespace App\Domain\Safe;

enum SafeTransactionPurpose: string
{
    case Deployment = 'deployment';
    case ConnectYieldPool = 'connect_yield_pool';
}
