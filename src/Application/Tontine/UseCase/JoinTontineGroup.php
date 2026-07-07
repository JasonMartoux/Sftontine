<?php

declare(strict_types=1);

namespace App\Application\Tontine\UseCase;

use App\Application\Shared\Result;
use App\Application\Tontine\Dto\GroupSummary;
use App\Application\Tontine\Port\TontineGroupRepositoryInterface;
use App\Domain\Identity\User;
use App\Domain\Tontine\Exception\AlreadyMemberException;
use App\Domain\Tontine\Exception\GroupClosedException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class JoinTontineGroup
{
    public function __construct(
        private TontineGroupRepositoryInterface $groups,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return Result<GroupSummary, JoinTontineGroupError>
     */
    public function __invoke(User $user, int $groupId): Result
    {
        $group = $this->groups->find($groupId);
        if (null === $group) {
            return Result::failure(JoinTontineGroupError::GroupNotFound);
        }

        try {
            $group->join($user, $this->clock->now());
        } catch (GroupClosedException) {
            return Result::failure(JoinTontineGroupError::GroupClosed);
        } catch (AlreadyMemberException) {
            return Result::failure(JoinTontineGroupError::AlreadyMember);
        }

        $this->groups->save($group);

        foreach ($group->releaseEvents() as $event) {
            $this->logger->info('Tontine group event: {event}', ['event' => $event::class]);
        }

        return Result::success(new GroupSummary(
            id: $groupId,
            name: $group->name,
            amountDisplay: $group->contributionAmountDisplay(),
            periodicity: $group->periodicity->value,
            memberCount: $group->memberCount,
        ));
    }
}
