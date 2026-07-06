<?php

declare(strict_types=1);

namespace App\Application\Vault\Port;

use App\Domain\Identity\WalletAddress;
use App\Domain\Vault\YieldSnapshot;

interface YieldSnapshotRepositoryInterface
{
    public function save(YieldSnapshot $snapshot): void;

    public function findLatestFor(WalletAddress $walletAddress): ?YieldSnapshot;

    /**
     * Most recent snapshot across all members — used to source the protocol-wide APR
     * for visitors without a position of their own yet.
     */
    public function findMostRecent(): ?YieldSnapshot;
}
