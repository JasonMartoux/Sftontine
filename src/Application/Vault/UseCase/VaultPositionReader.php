<?php

declare(strict_types=1);

namespace App\Application\Vault\UseCase;

use App\Application\Vault\Port\BlockchainReaderInterface;
use App\Application\Vault\Port\VaultAddressResolverInterface;
use App\Domain\Identity\WalletAddress;
use App\Domain\Vault\VaultPosition;
use BcMath\Number;
use Money\Currency;
use Money\Money;

/**
 * Reads a member's full vault position: ERC-4626 shares/principal on the vault itself,
 * plus the Superfluid GDA yield stream on the YIELD_POOL (a different contract — see
 * config/abi/README.md for why this needs three separate contracts).
 */
final readonly class VaultPositionReader
{
    public function __construct(
        private BlockchainReaderInterface $blockchainReader,
        private VaultAddressResolverInterface $addressResolver,
        private string $vaultAddress,
        private string $gdaForwarderAddress,
    ) {
    }

    public function read(WalletAddress $wallet): VaultPosition
    {
        $user = (string) $wallet;

        $shares = $this->callUint($this->vaultAddress, 'balanceOf(address)', [$user]);
        $principal = $this->callUint($this->vaultAddress, 'previewRedeem(uint256)', [(string) $shares]);
        $maxDeposit = $this->callUint($this->vaultAddress, 'maxDeposit(address)', [$user]);

        $yieldPool = $this->addressResolver->yieldPoolAddress();

        $yieldReceived = $this->callUint($yieldPool, 'getTotalAmountReceivedByMember(address)', [$user]);
        $flowRate = $this->callInt96($yieldPool, 'getMemberFlowRate(address)', [$user]);
        $connected = $this->callBool($this->gdaForwarderAddress, 'isMemberConnected(address,address)', [$yieldPool, $user]);
        $aprBasisPoints = $this->callUint($this->addressResolver->fundManagerAddress(), 'stableYieldRate()');

        return new VaultPosition(
            shares: $shares,
            principal: new Money((string) $principal, new Currency('USDC')),
            yieldReceived: $yieldReceived,
            flowRate: $flowRate,
            connected: $connected,
            paused: 0 === $maxDeposit->compare(0),
            aprBasisPoints: $aprBasisPoints,
        );
    }

    /**
     * @param list<string> $args
     */
    private function callUint(string $to, string $signature, array $args = []): Number
    {
        [$value] = $this->blockchainReader->call($to, $signature, $args, ['uint256']);
        \assert(\is_string($value) && is_numeric($value));

        return new Number($value);
    }

    /**
     * @param list<string> $args
     */
    private function callInt96(string $to, string $signature, array $args = []): Number
    {
        [$value] = $this->blockchainReader->call($to, $signature, $args, ['int96']);
        \assert(\is_string($value) && is_numeric($value));

        return new Number($value);
    }

    /**
     * @param list<string> $args
     */
    private function callBool(string $to, string $signature, array $args = []): bool
    {
        [$value] = $this->blockchainReader->call($to, $signature, $args, ['bool']);
        \assert(\is_bool($value));

        return $value;
    }
}
