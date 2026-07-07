<?php

declare(strict_types=1);

namespace App\Application\Tontine\Dto;

final readonly class ContributionView
{
    public function __construct(
        public int $groupId,
        public string $amountDisplay,
        public string $txHash,
        public \DateTimeImmutable $contributedAt,
    ) {
    }
}
