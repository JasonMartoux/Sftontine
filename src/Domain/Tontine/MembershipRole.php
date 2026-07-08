<?php

declare(strict_types=1);

namespace App\Domain\Tontine;

enum MembershipRole: string
{
    case Admin = 'admin';
    case Member = 'member';
}
