<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateways;

use App\Application\Vault\Port\BlockchainReaderInterface;
use App\Application\Vault\Port\VaultAddressResolverInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class CachedVaultAddressResolver implements VaultAddressResolverInterface
{
    public function __construct(
        private BlockchainReaderInterface $blockchainReader,
        private CacheInterface $cache,
        private string $vaultAddress,
    ) {
    }

    public function fundManagerAddress(): string
    {
        return $this->cache->get('vault.fund_manager_address', function (ItemInterface $item): string {
            $item->expiresAfter(null);

            [$address] = $this->blockchainReader->call($this->vaultAddress, 'FUND_MANAGER()', [], ['address']);
            \assert(\is_string($address));

            return $address;
        });
    }

    public function yieldPoolAddress(): string
    {
        return $this->cache->get('vault.yield_pool_address', function (ItemInterface $item): string {
            $item->expiresAfter(null);

            [$address] = $this->blockchainReader->call($this->fundManagerAddress(), 'YIELD_POOL()', [], ['address']);
            \assert(\is_string($address));

            return $address;
        });
    }
}
