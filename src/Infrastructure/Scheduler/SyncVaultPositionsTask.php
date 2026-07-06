<?php

declare(strict_types=1);

namespace App\Infrastructure\Scheduler;

use App\Application\Vault\UseCase\SyncVaultPositions;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

#[AsPeriodicTask(frequency: 300)]
final readonly class SyncVaultPositionsTask
{
    public function __construct(
        private SyncVaultPositions $syncVaultPositions,
    ) {
    }

    public function __invoke(): void
    {
        ($this->syncVaultPositions)();
    }
}
