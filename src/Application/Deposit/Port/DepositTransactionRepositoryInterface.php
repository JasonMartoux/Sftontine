<?php

declare(strict_types=1);

namespace App\Application\Deposit\Port;

use App\Domain\Deposit\DepositTransaction;
use App\Domain\Deposit\TransactionHash;
use App\Domain\Identity\WalletAddress;

interface DepositTransactionRepositoryInterface
{
    public function save(DepositTransaction $depositTransaction): void;

    public function findByTxHash(TransactionHash $txHash): ?DepositTransaction;

    /**
     * @return list<DepositTransaction>
     */
    public function findAllPending(): array;

    public function findLatestFor(WalletAddress $walletAddress): ?DepositTransaction;
}
