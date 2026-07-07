<?php

declare(strict_types=1);

namespace App\Tests\Application\Tontine\UseCase;

use App\Application\Tontine\Port\TontineGroupRepositoryInterface;
use App\Application\Tontine\UseCase\CreateTontineGroup;
use App\Application\Tontine\UseCase\CreateTontineGroupCommand;
use App\Application\Tontine\UseCase\CreateTontineGroupError;
use App\Domain\Identity\User;
use App\Domain\Identity\WalletAddress;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final class CreateTontineGroupTest extends TestCase
{
    private const NOW = '2026-01-01T00:00:00+00:00';

    public function testCreatesGroupAndReturnsSummaryOnSuccess(): void
    {
        $repository = $this->createMock(TontineGroupRepositoryInterface::class);
        $repository->expects(self::once())->method('save');

        $useCase = new CreateTontineGroup($repository, $this->clock(), $this->logger());
        $command = self::validCommand();

        $result = $useCase(self::alice(), $command);

        self::assertTrue($result->isSuccess);
        $summary = $result->value();
        self::assertSame('Tontine famille', $summary->name);
        self::assertSame('25.000000', $summary->amountDisplay);
        self::assertSame('weekly', $summary->periodicity);
        self::assertSame(1, $summary->memberCount);
    }

    public function testRejectsInvalidPeriodicityWithoutSaving(): void
    {
        $repository = $this->createMock(TontineGroupRepositoryInterface::class);
        $repository->expects(self::never())->method('save');

        $useCase = new CreateTontineGroup($repository, $this->clock(), $this->logger());
        $command = self::validCommand();
        $command->periodicity = 'yearly';

        $result = $useCase(self::alice(), $command);

        self::assertFalse($result->isSuccess);
        self::assertSame(CreateTontineGroupError::InvalidPeriodicity, $result->error());
    }

    public function testRejectsNonNumericAmountWithoutSaving(): void
    {
        $repository = $this->createMock(TontineGroupRepositoryInterface::class);
        $repository->expects(self::never())->method('save');

        $useCase = new CreateTontineGroup($repository, $this->clock(), $this->logger());
        $command = self::validCommand();
        $command->amount = 'not-a-number';

        $result = $useCase(self::alice(), $command);

        self::assertFalse($result->isSuccess);
        self::assertSame(CreateTontineGroupError::InvalidAmount, $result->error());
    }

    public function testRejectsNonPositiveAmountWithoutSaving(): void
    {
        $repository = $this->createMock(TontineGroupRepositoryInterface::class);
        $repository->expects(self::never())->method('save');

        $useCase = new CreateTontineGroup($repository, $this->clock(), $this->logger());
        $command = self::validCommand();
        $command->amount = '0';

        $result = $useCase(self::alice(), $command);

        self::assertFalse($result->isSuccess);
        self::assertSame(CreateTontineGroupError::InvalidAmount, $result->error());
    }

    public function testRejectsTooShortNameWithoutSaving(): void
    {
        $repository = $this->createMock(TontineGroupRepositoryInterface::class);
        $repository->expects(self::never())->method('save');

        $useCase = new CreateTontineGroup($repository, $this->clock(), $this->logger());
        $command = self::validCommand();
        $command->name = 'ab';

        $result = $useCase(self::alice(), $command);

        self::assertFalse($result->isSuccess);
        self::assertSame(CreateTontineGroupError::InvalidName, $result->error());
    }

    public function testRejectsInvalidCycleLengthWithoutSaving(): void
    {
        $repository = $this->createMock(TontineGroupRepositoryInterface::class);
        $repository->expects(self::never())->method('save');

        $useCase = new CreateTontineGroup($repository, $this->clock(), $this->logger());
        $command = self::validCommand();
        $command->installmentsPerCycle = 1;

        $result = $useCase(self::alice(), $command);

        self::assertFalse($result->isSuccess);
        self::assertSame(CreateTontineGroupError::InvalidCycleLength, $result->error());
    }

    private static function validCommand(): CreateTontineGroupCommand
    {
        $command = new CreateTontineGroupCommand();
        $command->name = 'Tontine famille';
        $command->amount = '25';
        $command->periodicity = 'weekly';
        $command->installmentsPerCycle = 12;

        return $command;
    }

    private static function alice(): User
    {
        return User::registerFromPrivy('did:privy:alice', 'alice@example.com', new WalletAddress('0x1111111111111111111111111111111111111111'));
    }

    private function clock(): ClockInterface
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable(self::NOW));

        return $clock;
    }

    private function logger(): LoggerInterface
    {
        return $this->createStub(LoggerInterface::class);
    }
}
