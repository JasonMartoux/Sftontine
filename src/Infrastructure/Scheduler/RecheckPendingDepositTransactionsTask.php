<?php

declare(strict_types=1);

namespace App\Infrastructure\Scheduler;

use App\Application\Deposit\UseCase\RecheckPendingDepositTransactions;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

#[AsPeriodicTask(frequency: 60)]
final readonly class RecheckPendingDepositTransactionsTask
{
    public function __construct(
        private RecheckPendingDepositTransactions $recheckPendingDepositTransactions,
    ) {
    }

    public function __invoke(): void
    {
        ($this->recheckPendingDepositTransactions)();
    }
}
