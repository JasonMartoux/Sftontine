<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

use BcMath\Number;

/**
 * Decoded `Deposit(address indexed sender, address indexed owner, uint256 assets, uint256 shares)`
 * event log from the vault (ERC-4626 standard), as read from a transaction receipt.
 */
final readonly class DepositEvent
{
    public function __construct(
        public string $sender,
        public string $owner,
        public Number $assets,
        public Number $shares,
    ) {
    }
}
