<?php

declare(strict_types=1);

namespace App\Presentation\Http\Dto;

use App\Application\Tontine\Dto\GroupSummary;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: GroupSummary::class)]
final class TontineGroupListItem
{
    public int $id = 0;

    public string $name = '';

    public string $amountDisplay = '';

    #[Map(source: 'periodicity', transform: [self::class, 'frenchLabel'])]
    public string $periodicityLabel = '';

    public int $memberCount = 0;

    public static function frenchLabel(mixed $value): string
    {
        \assert(\is_string($value));

        return match ($value) {
            'weekly' => 'Hebdomadaire',
            'biweekly' => 'Bimensuelle',
            'monthly' => 'Mensuelle',
            'semiannual' => 'Semestrielle',
            'punctual' => 'Ponctuelle',
            default => $value,
        };
    }
}
