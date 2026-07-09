<?php

declare(strict_types=1);

namespace App\Infrastructure\Scheduler;

use App\Application\Safe\UseCase\RecheckPendingSafeTransactions;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

#[AsPeriodicTask(frequency: 60)]
final readonly class RecheckPendingSafeTransactionsTask
{
    public function __construct(
        private RecheckPendingSafeTransactions $recheck,
    ) {
    }

    public function __invoke(): void
    {
        ($this->recheck)();
    }
}
