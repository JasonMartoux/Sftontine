<?php

declare(strict_types=1);

namespace App\Domain\Tontine\Event;

final readonly class MemberJoined
{
    public function __construct(
        public string $groupName,
        public ?int $userId,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
