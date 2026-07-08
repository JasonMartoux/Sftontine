<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

use App\Domain\Identity\WalletAddress;
use BcMath\Number;
use Doctrine\ORM\Mapping as ORM;
use Money\Currency;
use Money\Money;

/**
 * Tracks a deposit from the moment its txHash is submitted (pending) until its receipt is
 * confirmed on-chain (confirmed/failed). A member's contribution is never recorded without
 * a valid receipt — see applyReceipt().
 */
#[ORM\Entity]
#[ORM\Table(name: 'deposit_transaction')]
#[ORM\Index(columns: ['status'], name: 'idx_deposit_transaction_status')]
final class DepositTransaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    public private(set) ?int $id = null;

    private function __construct(
        #[ORM\Column(name: 'wallet_address', type: 'wallet_address', length: 42)]
        public private(set) WalletAddress $walletAddress,
        #[ORM\Column(name: 'tx_hash', type: 'transaction_hash', length: 66, unique: true)]
        public private(set) TransactionHash $txHash,
        #[ORM\Column(name: 'amount_minor_units', type: 'string', length: 78, nullable: true)]
        public private(set) ?string $amountMinorUnits,
        #[ORM\Column(name: 'status', type: 'string', length: 16, enumType: DepositTransactionStatus::class)]
        public private(set) DepositTransactionStatus $status,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        public readonly \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'confirmed_at', type: 'datetime_immutable', nullable: true)]
        public private(set) ?\DateTimeImmutable $confirmedAt,
    ) {
    }

    public static function pending(WalletAddress $walletAddress, TransactionHash $txHash): self
    {
        return new self($walletAddress, $txHash, null, DepositTransactionStatus::Pending, new \DateTimeImmutable(), null);
    }

    /**
     * The only path from `pending` to `confirmed`: the receipt must report success and an
     * emitted `Deposit` event whose `sender` (the actual signer) matches this transaction's wallet
     * — not `owner`/`receiver`, which for a group deposit is the group's Safe, never the depositing
     * member's own wallet. Anything else (reverted tx, missing event, event for a different sender)
     * is treated as failed.
     */
    public function applyReceipt(TransactionReceipt $receipt): void
    {
        if ($receipt->success
            && null !== $receipt->depositEvent
            && $this->walletAddress->equals(new WalletAddress($receipt->depositEvent->sender))
        ) {
            $this->amountMinorUnits = (string) $receipt->depositEvent->assets;
            $this->status = DepositTransactionStatus::Confirmed;
        } else {
            $this->status = DepositTransactionStatus::Failed;
        }

        $this->confirmedAt = new \DateTimeImmutable();
    }

    public function isPending(): bool
    {
        return DepositTransactionStatus::Pending === $this->status;
    }

    public function amount(): ?Money
    {
        if (null === $this->amountMinorUnits) {
            return null;
        }

        \assert(is_numeric($this->amountMinorUnits));

        return new Money($this->amountMinorUnits, new Currency('USDC'));
    }

    /**
     * Human-readable USDC amount (6-dec minor units / 1e6), for display only.
     */
    public function amountDisplay(): ?string
    {
        \assert(null === $this->amountMinorUnits || is_numeric($this->amountMinorUnits));

        return null === $this->amountMinorUnits ? null : (string) (new Number($this->amountMinorUnits))->div('1000000', 6);
    }
}
