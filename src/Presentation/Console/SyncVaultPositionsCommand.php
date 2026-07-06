<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Application\Vault\UseCase\SyncVaultPositions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Manually triggers the same sync the periodic scheduler task runs — useful for ops/debugging
 * without waiting for the next scheduled tick.
 */
#[AsCommand(name: 'app:vault:sync', description: 'Sync every registered member\'s vault position into a YieldSnapshot.')]
final class SyncVaultPositionsCommand extends Command
{
    public function __construct(
        private readonly SyncVaultPositions $syncVaultPositions,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ($this->syncVaultPositions)();
        $output->writeln('Vault positions synced.');

        return Command::SUCCESS;
    }
}
