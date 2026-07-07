<?php

declare(strict_types=1);

namespace App\Domain\Tontine;

use App\Domain\Deposit\TransactionHash;
use BcMath\Number;
use Doctrine\ORM\Mapping as ORM;
use Money\Currency;
use Money\Money;

#[ORM\Entity]
#[ORM\Table(name: 'tontine_contribution')]
final class Contribution
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    public private(set) ?int $id = null;

    public Money $amount {
        get {
            \assert(is_numeric($this->amountMinorUnits));

            return new Money($this->amountMinorUnits, new Currency('USDC'));
        }
    }

    private function __construct(
        #[ORM\ManyToOne(targetEntity: TontineGroup::class)]
        #[ORM\JoinColumn(name: 'group_id', nullable: false)]
        public readonly TontineGroup $group,
        #[ORM\ManyToOne(targetEntity: Membership::class)]
        #[ORM\JoinColumn(name: 'membership_id', nullable: false)]
        public readonly Membership $membership,
        #[ORM\ManyToOne(targetEntity: SavingsCycle::class)]
        #[ORM\JoinColumn(name: 'cycle_id', nullable: false)]
        public readonly SavingsCycle $cycle,
        #[ORM\Column(name: 'tx_hash', type: 'transaction_hash', length: 66, unique: true)]
        public readonly TransactionHash $txHash,
        #[ORM\Column(name: 'amount_minor_units', type: 'string', length: 78)]
        public readonly string $amountMinorUnits,
        #[ORM\Column(name: 'contributed_at', type: 'datetime_immutable')]
        public readonly \DateTimeImmutable $contributedAt,
    ) {
    }

    public static function record(Membership $membership, SavingsCycle $cycle, TransactionHash $txHash, Money $amount, \DateTimeImmutable $contributedAt): self
    {
        return new self($membership->group, $membership, $cycle, $txHash, $amount->getAmount(), $contributedAt);
    }

    /**
     * Human-readable USDC amount (6-dec minor units / 1e6), for display only.
     */
    public function amountDisplay(): string
    {
        \assert(is_numeric($this->amountMinorUnits));

        return (string) (new Number($this->amountMinorUnits))->div('1000000', 6);
    }
}
