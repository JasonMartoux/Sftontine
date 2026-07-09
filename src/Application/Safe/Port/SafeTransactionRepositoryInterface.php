<?php

declare(strict_types=1);

namespace App\Application\Safe\Port;

use App\Domain\Deposit\TransactionHash;
use App\Domain\Safe\SafeTransaction;
use App\Domain\Safe\SafeTransactionPurpose;

interface SafeTransactionRepositoryInterface
{
    public function save(SafeTransaction $transaction): void;

    public function findByTxHash(TransactionHash $txHash): ?SafeTransaction;

    /**
     * @return list<SafeTransaction>
     */
    public function findAllPending(): array;

    public function findLatestForGroup(int $groupId, SafeTransactionPurpose $purpose): ?SafeTransaction;
}
