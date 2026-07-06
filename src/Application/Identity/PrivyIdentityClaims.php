<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\WalletAddress;

final readonly class PrivyIdentityClaims
{
    public function __construct(
        public string $subjectId,
        public ?string $email,
        public WalletAddress $walletAddress,
    ) {
    }
}
