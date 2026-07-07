<?php

declare(strict_types=1);

namespace App\Domain\Tontine\Exception;

final class InvalidContributionAmountException extends \InvalidArgumentException
{
    public static function forNonPositiveAmount(): self
    {
        return new self('The contribution amount must be strictly positive.');
    }
}
