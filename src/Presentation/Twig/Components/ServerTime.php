<?php

declare(strict_types=1);

namespace App\Presentation\Twig\Components;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class ServerTime
{
    use DefaultActionTrait;

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
