<?php

declare(strict_types=1);

namespace App\Application\Safe\Dto;

final readonly class SafeExecutionReceipt
{
    public function __construct(
        public bool $success,
    ) {
    }
}
