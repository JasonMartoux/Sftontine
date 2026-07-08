<?php

declare(strict_types=1);

namespace App\Domain\Tontine;

use App\Domain\Tontine\Exception\CycleClosedException;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tontine_savings_cycle')]
final class SavingsCycle
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    public private(set) ?int $id = null;

    private function __construct(
        #[ORM\ManyToOne(targetEntity: TontineGroup::class)]
        #[ORM\JoinColumn(name: 'group_id', nullable: false)]
        public readonly TontineGroup $group,
        #[ORM\Column(name: 'cycle_number', type: 'integer')]
        public readonly int $number,
        #[ORM\Column(name: 'starts_at', type: 'datetime_immutable')]
        public readonly \DateTimeImmutable $startsAt,
        #[ORM\Column(name: 'ends_at', type: 'datetime_immutable', nullable: true)]
        public readonly ?\DateTimeImmutable $endsAt,
        #[ORM\Column(name: 'status', type: 'string', length: 16, enumType: SavingsCycleStatus::class)]
        public private(set) SavingsCycleStatus $status,
    ) {
    }

    public static function open(TontineGroup $group, int $number, \DateTimeImmutable $startsAt, ?\DateTimeImmutable $endsAt): self
    {
        return new self($group, $number, $startsAt, $endsAt, SavingsCycleStatus::Open);
    }

    public function isOpen(): bool
    {
        return SavingsCycleStatus::Open === $this->status;
    }

    public function close(): void
    {
        if (!$this->isOpen()) {
            throw CycleClosedException::create();
        }

        $this->status = SavingsCycleStatus::Closed;
    }
}
