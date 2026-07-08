<?php

declare(strict_types=1);

namespace App\Application\Tontine\Dto;

final readonly class GroupPotView
{
    /**
     * @param list<MemberPotView> $members
     */
    public function __construct(
        public int $groupId,
        public string $name,
        public string $periodicity,
        public string $potTotalDisplay,
        public string $estimatedYieldDisplay,
        public string $flowRatePerSecondDisplay,
        public int $computedAtTimestamp,
        public int $aprBasisPoints,
        public ?int $cycleNumber,
        public ?\DateTimeImmutable $cycleEndsAt,
        public array $members,
    ) {
    }
}
