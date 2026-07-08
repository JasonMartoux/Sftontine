<?php

declare(strict_types=1);

namespace App\Domain\Tontine\Exception;

final class ContributionAmountMismatchException extends \InvalidArgumentException
{
    public static function forAmount(string $expectedAmount, string $actualAmount): self
    {
        return new self(\sprintf('Expected a contribution of "%s" but received "%s".', $expectedAmount, $actualAmount));
    }
}
