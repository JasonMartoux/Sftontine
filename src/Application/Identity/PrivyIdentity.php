<?php

declare(strict_types=1);

namespace App\Application\Identity;

final readonly class PrivyIdentity
{
    public function __construct(
        public string $subjectId,
    ) {
    }
}
