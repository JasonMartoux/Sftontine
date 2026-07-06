<?php

declare(strict_types=1);

namespace App\Application\Vault\UseCase;

use App\Application\Identity\Port\UserRepositoryInterface;
use App\Application\Vault\Exception\BlockchainCallException;
use App\Application\Vault\Port\YieldSnapshotRepositoryInterface;
use App\Domain\Vault\YieldSnapshot;
use Psr\Log\LoggerInterface;

/**
 * Reads every registered member's vault position and persists a snapshot. One member's RPC
 * failure must not abort the sync for the others — each is isolated and logged.
 */
final readonly class SyncVaultPositions
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private VaultPositionReader $positionReader,
        private YieldSnapshotRepositoryInterface $snapshotRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        foreach ($this->userRepository->findAll() as $user) {
            try {
                $position = $this->positionReader->read($user->walletAddress);
                $this->snapshotRepository->save(YieldSnapshot::fromPosition($user->walletAddress, $position));
            } catch (BlockchainCallException $e) {
                $this->logger->error('Vault position sync failed for {wallet}: {message}', [
                    'wallet' => (string) $user->walletAddress,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}
