<?php

declare(strict_types=1);

namespace App\Application\Tontine\Dto;

final readonly class GroupSummary
{
    public function __construct(
        public ?int $id,
        public string $name,
        public string $amountDisplay,
        public string $periodicity,
        public int $memberCount,
    ) {
    }
}
