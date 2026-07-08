<?php

declare(strict_types=1);

namespace App\Application\Safe\UseCase;

use App\Application\Safe\Port\SafeReceiptReaderInterface;
use App\Application\Safe\Port\SafeTransactionRepositoryInterface;
use App\Application\Tontine\Port\TontineGroupRepositoryInterface;
use App\Application\Vault\Exception\BlockchainCallException;
use App\Domain\Identity\WalletAddress;
use App\Domain\Safe\SafeTransactionPurpose;
use App\Domain\Safe\SafeTransactionStatus;
use Psr\Log\LoggerInterface;

/**
 * Periodic catch-up for Safe transactions (deployment or execTransaction) whose txHash was
 * submitted but not yet mined at confirmation time. Mirrors RecheckPendingDepositTransactions:
 * one transaction's RPC failure must not abort the recheck for the others.
 */
final readonly class RecheckPendingSafeTransactions
{
    public function __construct(
        private SafeTransactionRepositoryInterface $safeTransactions,
        private TontineGroupRepositoryInterface $groups,
        private SafeReceiptReaderInterface $receiptReader,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        foreach ($this->safeTransactions->findAllPending() as $safeTransaction) {
            try {
                if (SafeTransactionPurpose::Deployment === $safeTransaction->purpose) {
                    $receipt = $this->receiptReader->getDeploymentReceipt($safeTransaction->txHash);
                    if (null === $receipt) {
                        continue;
                    }

                    $proxyAddress = null !== $receipt->proxyAddress ? new WalletAddress($receipt->proxyAddress) : null;
                    $safeTransaction->applyDeploymentReceipt($receipt->success, $proxyAddress);

                    if (SafeTransactionStatus::Confirmed === $safeTransaction->status && null !== $proxyAddress) {
                        $group = $this->groups->find($safeTransaction->groupId);
                        if (null !== $group) {
                            $group->provisionSafe($proxyAddress);
                            $this->groups->save($group);
                        }
                    }
                } else {
                    \assert(null !== $safeTransaction->safeTxHash);
                    $receipt = $this->receiptReader->getExecutionReceipt($safeTransaction->txHash, $safeTransaction->safeTxHash);
                    if (null === $receipt) {
                        continue;
                    }

                    $safeTransaction->applyExecutionReceipt($receipt->success);
                }

                $this->safeTransactions->save($safeTransaction);
            } catch (BlockchainCallException $e) {
                $this->logger->error('Safe tx recheck failed for {txHash}: {message}', [
                    'txHash' => (string) $safeTransaction->txHash,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}
