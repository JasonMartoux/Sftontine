<?php

declare(strict_types=1);

namespace App\Application\Deposit\Port;

use App\Domain\Deposit\TransactionHash;
use App\Domain\Deposit\TransactionReceipt;

interface TransactionReceiptReaderInterface
{
    /**
     * @return TransactionReceipt|null null when the transaction is not yet mined
     */
    public function getReceipt(TransactionHash $txHash): ?TransactionReceipt;
}
