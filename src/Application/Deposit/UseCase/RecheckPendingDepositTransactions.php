<?php

declare(strict_types=1);

namespace App\Application\Deposit\UseCase;

use App\Application\Deposit\Port\DepositTransactionRepositoryInterface;
use App\Application\Deposit\Port\TransactionReceiptReaderInterface;
use App\Application\Vault\Exception\BlockchainCallException;
use Psr\Log\LoggerInterface;

/**
 * Periodic catch-up for deposits whose txHash was submitted but not yet mined at
 * confirmation time. One transaction's RPC failure must not abort the recheck for the
 * others — each is isolated and logged, mirroring SyncVaultPositions.
 */
final readonly class RecheckPendingDepositTransactions
{
    public function __construct(
        private DepositTransactionRepositoryInterface $repository,
        private TransactionReceiptReaderInterface $receiptReader,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        foreach ($this->repository->findAllPending() as $depositTransaction) {
            try {
                $receipt = $this->receiptReader->getReceipt($depositTransaction->txHash);

                if (null === $receipt) {
                    continue;
                }

                $depositTransaction->applyReceipt($receipt);
                $this->repository->save($depositTransaction);
            } catch (BlockchainCallException $e) {
                $this->logger->error('Deposit tx recheck failed for {txHash}: {message}', [
                    'txHash' => (string) $depositTransaction->txHash,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}
