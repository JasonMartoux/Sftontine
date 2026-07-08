<?php

declare(strict_types=1);

namespace App\Application\Tontine\UseCase;

enum JoinTontineGroupError: string
{
    case GroupNotFound = 'group_not_found';
    case GroupClosed = 'group_closed';
    case AlreadyMember = 'already_member';
}
