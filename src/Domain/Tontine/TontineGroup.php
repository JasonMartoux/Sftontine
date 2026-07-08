<?php

declare(strict_types=1);

namespace App\Domain\Tontine;

use App\Domain\Deposit\TransactionHash;
use App\Domain\Identity\User;
use App\Domain\Tontine\Event\ContributionRecorded;
use App\Domain\Tontine\Event\CycleClosed;
use App\Domain\Tontine\Event\MemberJoined;
use App\Domain\Tontine\Exception\AlreadyMemberException;
use App\Domain\Tontine\Exception\ContributionAlreadyRecordedException;
use App\Domain\Tontine\Exception\ContributionAmountMismatchException;
use App\Domain\Tontine\Exception\CycleClosedException;
use App\Domain\Tontine\Exception\GroupClosedException;
use App\Domain\Tontine\Exception\InvalidContributionAmountException;
use App\Domain\Tontine\Exception\InvalidCycleLengthException;
use App\Domain\Tontine\Exception\InvalidGroupNameException;
use App\Domain\Tontine\Exception\NotAMemberException;
use BcMath\Number;
use Doctrine\ORM\Mapping as ORM;
use Money\Currency;
use Money\Money;

/**
 * Membership/SavingsCycle/Contribution are independently mapped entities (unidirectional
 * ManyToOne to this aggregate, no Doctrine OneToMany here) so this class never depends on
 * Doctrine\Common\Collections — kept out of the Domain layer by deptrac.yaml on purpose.
 * Doctrine hydrates this entity's own columns via reflection (bypassing the constructor) but
 * leaves these plain-array fields at their `[]` default, so the repository must repopulate
 * them once via attachPersistedChildren() right after loading.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tontine_group')]
final class TontineGroup
{
    private const int MIN_NAME_LENGTH = 3;
    private const int MAX_NAME_LENGTH = 100;
    private const int MIN_INSTALLMENTS_PER_CYCLE = 2;
    private const int MAX_INSTALLMENTS_PER_CYCLE = 52;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    public private(set) ?int $id = null;

    /** @var list<Membership> */
    private array $memberships = [];

    /** @var list<SavingsCycle> */
    private array $cycles = [];

    /** @var list<Contribution> */
    private array $contributions = [];

    /** @var list<object> */
    private array $recordedEvents = [];

    public Money $contributionAmount {
        get {
            \assert(is_numeric($this->contributionAmountMinorUnits));

            return new Money($this->contributionAmountMinorUnits, new Currency('USDC'));
        }
    }

    public int $memberCount {
        get => \count($this->memberships);
    }

    private function __construct(
        #[ORM\Column(name: 'name', type: 'string', length: self::MAX_NAME_LENGTH)]
        public private(set) string $name,
        #[ORM\Column(name: 'contribution_amount_minor_units', type: 'string', length: 78)]
        public private(set) string $contributionAmountMinorUnits,
        #[ORM\Column(name: 'periodicity', type: 'string', length: 16, enumType: Periodicity::class)]
        public private(set) Periodicity $periodicity,
        #[ORM\Column(name: 'installments_per_cycle', type: 'integer')]
        public private(set) int $installmentsPerCycle,
        #[ORM\Column(name: 'status', type: 'string', length: 16, enumType: TontineGroupStatus::class)]
        public private(set) TontineGroupStatus $status,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        public readonly \DateTimeImmutable $createdAt,
    ) {
    }

    public static function create(User $creator, string $name, Money $contributionAmount, Periodicity $periodicity, int $installmentsPerCycle, \DateTimeImmutable $now): self
    {
        $length = mb_strlen($name);
        if ($length < self::MIN_NAME_LENGTH || $length > self::MAX_NAME_LENGTH) {
            throw InvalidGroupNameException::forValue($name);
        }

        if ($contributionAmount->isZero() || $contributionAmount->isNegative()) {
            throw InvalidContributionAmountException::forNonPositiveAmount();
        }

        if ($periodicity->isRecurring()) {
            if ($installmentsPerCycle < self::MIN_INSTALLMENTS_PER_CYCLE || $installmentsPerCycle > self::MAX_INSTALLMENTS_PER_CYCLE) {
                throw InvalidCycleLengthException::forValue($installmentsPerCycle);
            }
        } else {
            $installmentsPerCycle = 1;
        }

        $group = new self($name, $contributionAmount->getAmount(), $periodicity, $installmentsPerCycle, TontineGroupStatus::Active, $now);

        $endsAt = null;
        if ($periodicity->isRecurring()) {
            $endsAt = $now;
            for ($i = 0; $i < $installmentsPerCycle; ++$i) {
                $endsAt = $endsAt->add($periodicity->dateInterval());
            }
        }
        $group->cycles[] = SavingsCycle::open($group, 1, $now, $endsAt);

        $membership = Membership::admin($group, $creator, $now);
        $group->memberships[] = $membership;
        $group->record(new MemberJoined($group->name, $creator->id, $now));

        return $group;
    }

    public function join(User $user, \DateTimeImmutable $now): Membership
    {
        if (TontineGroupStatus::Active !== $this->status) {
            throw GroupClosedException::create();
        }

        if (null !== $this->membershipFor($user)) {
            throw AlreadyMemberException::forUserId($user->id);
        }

        $membership = Membership::member($this, $user, $now);
        $this->memberships[] = $membership;
        $this->record(new MemberJoined($this->name, $user->id, $now));

        return $membership;
    }

    public function recordContribution(User $user, TransactionHash $txHash, Money $amount, \DateTimeImmutable $now): Contribution
    {
        $membership = $this->membershipFor($user);
        if (null === $membership) {
            throw NotAMemberException::forUserId($user->id);
        }

        if (!$amount->equals($this->contributionAmount)) {
            throw ContributionAmountMismatchException::forAmount($this->contributionAmountMinorUnits, $amount->getAmount());
        }

        $cycle = $this->currentCycle();
        if (null === $cycle) {
            throw CycleClosedException::create();
        }

        foreach ($this->contributions as $existing) {
            if ($existing->txHash->equals($txHash)) {
                throw ContributionAlreadyRecordedException::forTransactionHash($txHash->value);
            }
        }

        $contribution = Contribution::record($membership, $cycle, $txHash, $amount, $now);
        $this->contributions[] = $contribution;
        $this->record(new ContributionRecorded($this->name, $user->id, $amount->getAmount(), $txHash->value, $now));

        return $contribution;
    }

    public function closeCurrentCycle(\DateTimeImmutable $now): void
    {
        $cycle = $this->currentCycle();
        if (null === $cycle) {
            throw CycleClosedException::create();
        }

        $cycle->close();
        $this->record(new CycleClosed($this->name, $cycle->number, $now));
    }

    public function close(\DateTimeImmutable $now): void
    {
        $this->status = TontineGroupStatus::Closed;
    }

    public function currentCycle(): ?SavingsCycle
    {
        foreach ($this->cycles as $cycle) {
            if ($cycle->isOpen()) {
                return $cycle;
            }
        }

        return null;
    }

    public function membershipFor(User $user): ?Membership
    {
        foreach ($this->memberships as $membership) {
            if ($membership->isFor($user)) {
                return $membership;
            }
        }

        return null;
    }

    public function potTotal(): Money
    {
        $total = new Money('0', new Currency('USDC'));
        foreach ($this->contributions as $contribution) {
            $total = $total->add($contribution->amount);
        }

        return $total;
    }

    /**
     * Human-readable USDC amount (6-dec minor units / 1e6), for display only.
     */
    public function contributionAmountDisplay(): string
    {
        \assert(is_numeric($this->contributionAmountMinorUnits));

        return (string) (new Number($this->contributionAmountMinorUnits))->div('1000000', 6);
    }

    /**
     * @return list<object>
     */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }

    /**
     * @return list<Membership>
     */
    public function memberships(): array
    {
        return $this->memberships;
    }

    /**
     * @return list<SavingsCycle>
     */
    public function cycles(): array
    {
        return $this->cycles;
    }

    /**
     * @return list<Contribution>
     */
    public function contributions(): array
    {
        return $this->contributions;
    }

    /**
     * Doctrine hydrates this entity's own columns via reflection when loading an existing row,
     * but never touches these unmapped array fields. The repository must call this exactly once,
     * right after loading, with the children it queried separately by group id.
     *
     * @param list<Membership>   $memberships
     * @param list<SavingsCycle> $cycles
     * @param list<Contribution> $contributions
     */
    public function attachPersistedChildren(array $memberships, array $cycles, array $contributions): void
    {
        if ([] !== $this->memberships || [] !== $this->cycles || [] !== $this->contributions) {
            throw new \LogicException('Persisted children can only be attached once, right after loading.');
        }

        $this->memberships = $memberships;
        $this->cycles = $cycles;
        $this->contributions = $contributions;
    }

    private function record(object $event): void
    {
        $this->recordedEvents[] = $event;
    }
}
