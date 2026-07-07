<?php

declare(strict_types=1);

namespace App\Domain\Tontine\Exception;

final class ContributionAlreadyRecordedException extends \LogicException
{
    public static function forTransactionHash(string $txHash): self
    {
        return new self(\sprintf('Transaction "%s" has already been recorded as a contribution.', $txHash));
    }
}
