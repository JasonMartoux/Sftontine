<?php

declare(strict_types=1);

namespace App\Domain\Tontine\Event;

final readonly class CycleClosed
{
    public function __construct(
        public string $groupName,
        public int $cycleNumber,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
