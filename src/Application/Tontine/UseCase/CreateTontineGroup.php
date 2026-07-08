<?php

declare(strict_types=1);

namespace App\Application\Tontine\UseCase;

use App\Application\Shared\Result;
use App\Application\Tontine\Dto\GroupSummary;
use App\Application\Tontine\Port\TontineGroupRepositoryInterface;
use App\Domain\Identity\User;
use App\Domain\Tontine\Exception\InvalidContributionAmountException;
use App\Domain\Tontine\Exception\InvalidCycleLengthException;
use App\Domain\Tontine\Exception\InvalidGroupNameException;
use App\Domain\Tontine\Periodicity;
use App\Domain\Tontine\TontineGroup;
use BcMath\Number;
use Money\Currency;
use Money\Money;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class CreateTontineGroup
{
    public function __construct(
        private TontineGroupRepositoryInterface $groups,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return Result<GroupSummary, CreateTontineGroupError>
     */
    public function __invoke(User $creator, CreateTontineGroupCommand $command): Result
    {
        $periodicity = Periodicity::tryFrom($command->periodicity);
        if (null === $periodicity) {
            return Result::failure(CreateTontineGroupError::InvalidPeriodicity);
        }

        if (!is_numeric($command->amount)) {
            return Result::failure(CreateTontineGroupError::InvalidAmount);
        }
        $amountMinorUnits = (string) (new Number($command->amount))->mul('1000000', 0);

        try {
            $group = TontineGroup::create(
                $creator,
                $command->name,
                new Money($amountMinorUnits, new Currency('USDC')),
                $periodicity,
                $command->installmentsPerCycle,
                $this->clock->now(),
            );
        } catch (InvalidGroupNameException) {
            return Result::failure(CreateTontineGroupError::InvalidName);
        } catch (InvalidContributionAmountException) {
            return Result::failure(CreateTontineGroupError::InvalidAmount);
        } catch (InvalidCycleLengthException) {
            return Result::failure(CreateTontineGroupError::InvalidCycleLength);
        }

        $this->groups->save($group);

        foreach ($group->releaseEvents() as $event) {
            $this->logger->info('Tontine group event: {event}', ['event' => $event::class]);
        }

        return Result::success(new GroupSummary(
            id: $group->id,
            name: $group->name,
            amountDisplay: $group->contributionAmountDisplay(),
            periodicity: $periodicity->value,
            memberCount: $group->memberCount,
        ));
    }
}
