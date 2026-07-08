<?php

declare(strict_types=1);

namespace App\Application\Safe\UseCase;

enum GroupSafeError: string
{
    case GroupNotFound = 'group_not_found';
    case NotAnAdmin = 'not_an_admin';
}
