<?php

declare(strict_types=1);

namespace App\Domain\Safe;

use App\Domain\Deposit\TransactionHash;
use App\Domain\Identity\WalletAddress;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tracks a Safe deployment or execTransaction from the moment its txHash is submitted
 * (pending) until its receipt is confirmed on-chain (confirmed/failed) — same lifecycle
 * shape as DepositTransaction, but for a group's Safe rather than a member's deposit.
 * Not FK'd to TontineGroup (same precedent as DepositTransaction<->wallet): groupId is a
 * plain column, looked up via the group repository when a side effect is needed.
 */
#[ORM\Entity]
#[ORM\Table(name: 'safe_transaction')]
#[ORM\Index(columns: ['status'], name: 'idx_safe_transaction_status')]
#[ORM\Index(columns: ['group_id', 'purpose'], name: 'idx_safe_transaction_group_purpose')]
final class SafeTransaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    public private(set) ?int $id = null;

    private function __construct(
        #[ORM\Column(name: 'group_id', type: 'integer')]
        public private(set) int $groupId,
        #[ORM\Column(name: 'purpose', type: 'string', length: 32, enumType: SafeTransactionPurpose::class)]
        public private(set) SafeTransactionPurpose $purpose,
        #[ORM\Column(name: 'tx_hash', type: 'transaction_hash', length: 66, unique: true)]
        public private(set) TransactionHash $txHash,
        #[ORM\Column(name: 'safe_tx_hash', type: 'transaction_hash', length: 66, nullable: true)]
        public private(set) ?TransactionHash $safeTxHash,
        #[ORM\Column(name: 'safe_address', type: 'wallet_address', length: 42, nullable: true)]
        public private(set) ?WalletAddress $safeAddress,
        #[ORM\Column(name: 'status', type: 'string', length: 16, enumType: SafeTransactionStatus::class)]
        public private(set) SafeTransactionStatus $status,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        public readonly \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'confirmed_at', type: 'datetime_immutable', nullable: true)]
        public private(set) ?\DateTimeImmutable $confirmedAt,
    ) {
    }

    public static function pendingDeployment(int $groupId, TransactionHash $txHash): self
    {
        return new self($groupId, SafeTransactionPurpose::Deployment, $txHash, null, null, SafeTransactionStatus::Pending, new \DateTimeImmutable(), null);
    }

    public static function pendingExecution(int $groupId, SafeTransactionPurpose $purpose, TransactionHash $txHash, TransactionHash $safeTxHash): self
    {
        return new self($groupId, $purpose, $txHash, $safeTxHash, null, SafeTransactionStatus::Pending, new \DateTimeImmutable(), null);
    }

    public function applyDeploymentReceipt(bool $success, ?WalletAddress $proxyAddress): void
    {
        if ($success && null !== $proxyAddress) {
            $this->safeAddress = $proxyAddress;
            $this->status = SafeTransactionStatus::Confirmed;
        } else {
            $this->status = SafeTransactionStatus::Failed;
        }

        $this->confirmedAt = new \DateTimeImmutable();
    }

    public function applyExecutionReceipt(bool $success): void
    {
        $this->status = $success ? SafeTransactionStatus::Confirmed : SafeTransactionStatus::Failed;
        $this->confirmedAt = new \DateTimeImmutable();
    }

    public function isPending(): bool
    {
        return SafeTransactionStatus::Pending === $this->status;
    }
}
