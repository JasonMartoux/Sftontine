<?php

declare(strict_types=1);

namespace App\Application\Tontine\UseCase;

use App\Application\Deposit\Port\DepositTransactionRepositoryInterface;
use App\Application\Shared\Result;
use App\Application\Tontine\Dto\ContributionView;
use App\Application\Tontine\Port\TontineGroupRepositoryInterface;
use App\Domain\Deposit\DepositTransactionStatus;
use App\Domain\Deposit\TransactionHash;
use App\Domain\Identity\User;
use App\Domain\Tontine\Exception\ContributionAlreadyRecordedException;
use App\Domain\Tontine\Exception\ContributionAmountMismatchException;
use App\Domain\Tontine\Exception\CycleClosedException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class RecordContribution
{
    public function __construct(
        private TontineGroupRepositoryInterface $groups,
        private DepositTransactionRepositoryInterface $deposits,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return Result<ContributionView, RecordContributionError>
     */
    public function __invoke(User $user, int $groupId, TransactionHash $txHash): Result
    {
        $group = $this->groups->find($groupId);
        if (null === $group) {
            return Result::failure(RecordContributionError::GroupNotFound);
        }

        if (null === $group->membershipFor($user)) {
            return Result::failure(RecordContributionError::NotAMember);
        }

        $deposit = $this->deposits->findByTxHash($txHash);
        if (null === $deposit) {
            return Result::failure(RecordContributionError::DepositNotFound);
        }

        if (DepositTransactionStatus::Confirmed !== $deposit->status) {
            return Result::failure(RecordContributionError::DepositNotConfirmed);
        }

        if (!$deposit->walletAddress->equals($user->walletAddress)) {
            return Result::failure(RecordContributionError::DepositWalletMismatch);
        }

        $amount = $deposit->amount();
        \assert(null !== $amount);

        try {
            $contribution = $group->recordContribution($user, $txHash, $amount, $this->clock->now());
        } catch (ContributionAmountMismatchException) {
            return Result::failure(RecordContributionError::AmountMismatch);
        } catch (ContributionAlreadyRecordedException) {
            return Result::failure(RecordContributionError::AlreadyRecorded);
        } catch (CycleClosedException) {
            return Result::failure(RecordContributionError::CycleClosed);
        }

        $this->groups->save($group);

        foreach ($group->releaseEvents() as $event) {
            $this->logger->info('Tontine group event: {event}', ['event' => $event::class]);
        }

        return Result::success(new ContributionView(
            groupId: $groupId,
            amountDisplay: $contribution->amountDisplay(),
            txHash: $txHash->value,
            contributedAt: $contribution->contributedAt,
        ));
    }
}
