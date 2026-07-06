<?php

declare(strict_types=1);

namespace App\Domain\Vault;

use BcMath\Number;
use Money\Money;

/**
 * A member's position on the SuperVault: two independent components, never mixed —
 * principal (ERC-4626 shares, USDC 6-dec) and yield (Superfluid GDA stream, USDCx 18-dec).
 */
final readonly class VaultPosition
{
    public function __construct(
        public Number $shares,
        public Money $principal,
        public Number $yieldReceived,
        public Number $flowRate,
        public bool $connected,
        public bool $paused,
        /** Protocol-wide annualized rate, in basis points (100 = 1%) — not member-specific. */
        public Number $aprBasisPoints,
    ) {
    }
}
