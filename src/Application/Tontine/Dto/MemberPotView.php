<?php

declare(strict_types=1);

namespace App\Application\Tontine\Dto;

final readonly class MemberPotView
{
    public function __construct(
        public ?int $userId,
        public ?string $email,
        public string $walletAddress,
        public string $role,
        public string $contributedDisplay,
        public string $yieldShareDisplay,
        public int $expectedInstallments,
        public int $paidInstallments,
        public int $missedInstallments,
        public bool $isLate,
        public ?\DateTimeImmutable $nextDueDate,
    ) {
    }
}
