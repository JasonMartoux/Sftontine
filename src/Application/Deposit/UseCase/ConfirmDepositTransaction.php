<?php

declare(strict_types=1);

namespace App\Application\Deposit\UseCase;

use App\Application\Deposit\Port\DepositTransactionRepositoryInterface;
use App\Application\Deposit\Port\TransactionReceiptReaderInterface;
use App\Domain\Deposit\DepositTransaction;
use App\Domain\Deposit\TransactionHash;
use App\Domain\Identity\WalletAddress;

/**
 * Called right after the frontend submits a deposit's txHash. If the transaction isn't
 * mined yet, the deposit stays pending and RecheckPendingDepositTransactions will pick it
 * up later — it is never recorded as a contribution without a valid on-chain receipt.
 */
final readonly class ConfirmDepositTransaction
{
    public function __construct(
        private TransactionReceiptReaderInterface $receiptReader,
        private DepositTransactionRepositoryInterface $repository,
    ) {
    }

    public function __invoke(WalletAddress $walletAddress, TransactionHash $txHash): DepositTransaction
    {
        $depositTransaction = $this->repository->findByTxHash($txHash)
            ?? DepositTransaction::pending($walletAddress, $txHash);

        $receipt = $this->receiptReader->getReceipt($txHash);

        if (null !== $receipt) {
            $depositTransaction->applyReceipt($receipt);
        }

        $this->repository->save($depositTransaction);

        return $depositTransaction;
    }
}
