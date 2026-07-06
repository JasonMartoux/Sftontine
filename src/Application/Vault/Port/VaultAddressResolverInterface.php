<?php

declare(strict_types=1);

namespace App\Application\Vault\Port;

/**
 * Resolves the immutable contract addresses derived from the vault's own address:
 * FundManager (capital custodian) and the YIELD_POOL GDA pool it manages.
 * These never change once deployed, so implementations are expected to cache them.
 */
interface VaultAddressResolverInterface
{
    public function fundManagerAddress(): string;

    public function yieldPoolAddress(): string;
}
