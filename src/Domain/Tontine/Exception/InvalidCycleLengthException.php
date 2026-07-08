<?php

declare(strict_types=1);

namespace App\Domain\Tontine\Exception;

final class InvalidCycleLengthException extends \InvalidArgumentException
{
    public static function forValue(int $installmentsPerCycle): self
    {
        return new self(\sprintf('"%d" is not a valid number of installments per cycle (2 to 52).', $installmentsPerCycle));
    }
}
