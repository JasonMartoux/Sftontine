<?php

declare(strict_types=1);

namespace App\Application\Safe\Dto;

final readonly class GroupSafeView
{
    public function __construct(
        public int $groupId,
        public ?string $safeAddress,
        public string $status,
    ) {
    }
}
