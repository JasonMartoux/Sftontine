<?php

declare(strict_types=1);

namespace App\Domain\Tontine\Exception;

final class CycleClosedException extends \LogicException
{
    public static function create(): self
    {
        return new self('There is no open savings cycle for this tontine group.');
    }
}
