<?php

declare(strict_types=1);

namespace App\Application\Tontine\UseCase;

/**
 * Mutable, publicly-writable by design: this is the target of ObjectMapper mapping at the
 * Presentation boundary (Symfony\Component\ObjectMapper writes properties directly, bypassing
 * constructors — see src/Presentation/Http/Dto/CreateTontineGroupInput.php).
 */
final class CreateTontineGroupCommand
{
    public string $name = '';

    /**
     * Decimal USDC amount as entered by the user, e.g. "25.50".
     */
    public string $amount = '';

    public string $periodicity = '';

    public int $installmentsPerCycle = 0;
}
