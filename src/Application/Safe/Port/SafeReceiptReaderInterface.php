<?php

declare(strict_types=1);

namespace App\Application\Safe\Port;

use App\Application\Safe\Dto\SafeDeploymentReceipt;
use App\Application\Safe\Dto\SafeExecutionReceipt;
use App\Domain\Deposit\TransactionHash;

interface SafeReceiptReaderInterface
{
    /**
     * @return SafeDeploymentReceipt|null null when the transaction is not yet mined
     */
    public function getDeploymentReceipt(TransactionHash $txHash): ?SafeDeploymentReceipt;

    /**
     * @return SafeExecutionReceipt|null null when the transaction is not yet mined
     */
    public function getExecutionReceipt(TransactionHash $txHash, TransactionHash $safeTxHash): ?SafeExecutionReceipt;
}
