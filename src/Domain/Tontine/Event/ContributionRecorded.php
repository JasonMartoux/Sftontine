<?php

declare(strict_types=1);

namespace App\Domain\Tontine\Event;

final readonly class ContributionRecorded
{
    public function __construct(
        public string $groupName,
        public ?int $userId,
        public string $amountMinorUnits,
        public string $txHash,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
