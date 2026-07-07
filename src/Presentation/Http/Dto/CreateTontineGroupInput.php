<?php

declare(strict_types=1);

namespace App\Presentation\Http\Dto;

use App\Application\Tontine\UseCase\CreateTontineGroupCommand;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(target: CreateTontineGroupCommand::class)]
final class CreateTontineGroupInput
{
    public string $name = '';

    public string $amount = '';

    public string $periodicity = '';

    #[Map(target: 'installmentsPerCycle', transform: [self::class, 'toInt'])]
    public string $installments = '';

    public static function fromRequest(Request $request): self
    {
        $input = new self();
        $input->name = (string) $request->request->get('name', '');
        $input->amount = (string) $request->request->get('amount', '');
        $input->periodicity = (string) $request->request->get('periodicity', '');
        $input->installments = (string) $request->request->get('installments', '');

        return $input;
    }

    public static function toInt(mixed $value): int
    {
        \assert(\is_string($value));

        return (int) $value;
    }
}
