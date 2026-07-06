<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

/**
 * Result of `eth_getTransactionReceipt` relevant to confirming a deposit: whether the
 * transaction succeeded, and the decoded `Deposit` event if the vault emitted one.
 */
final readonly class TransactionReceipt
{
    public function __construct(
        public bool $success,
        public ?DepositEvent $depositEvent,
    ) {
    }
}
