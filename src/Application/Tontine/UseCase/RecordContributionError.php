<?php

declare(strict_types=1);

namespace App\Application\Tontine\UseCase;

enum RecordContributionError: string
{
    case GroupNotFound = 'group_not_found';
    case NotAMember = 'not_a_member';
    case DepositNotFound = 'deposit_not_found';
    case DepositNotConfirmed = 'deposit_not_confirmed';
    case DepositWalletMismatch = 'deposit_wallet_mismatch';
    case AmountMismatch = 'amount_mismatch';
    case AlreadyRecorded = 'already_recorded';
    case CycleClosed = 'cycle_closed';
}
