<?php

declare(strict_types=1);

namespace App\Tests\Application\Tontine\UseCase;

use App\Application\Tontine\Port\TontineGroupRepositoryInterface;
use App\Application\Tontine\UseCase\JoinTontineGroup;
use App\Application\Tontine\UseCase\JoinTontineGroupError;
use App\Domain\Identity\User;
use App\Domain\Identity\WalletAddress;
use App\Domain\Tontine\Periodicity;
use App\Domain\Tontine\TontineGroup;
use Money\Currency;
use Money\Money;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final class JoinTontineGroupTest extends TestCase
{
    private const NOW = '2026-01-05T00:00:00+00:00';

    public function testJoinsGroupAndReturnsSummaryOnSuccess(): void
    {
        $group = self::makeGroup(self::alice());

        $repository = $this->createMock(TontineGroupRepositoryInterface::class);
        $repository->expects(self::once())->method('find')->with(1)->willReturn($group);
        $repository->expects(self::once())->method('save')->with($group);

        $useCase = new JoinTontineGroup($repository, $this->clock(), $this->logger());
        $result = $useCase(self::bob(), 1);

        self::assertTrue($result->isSuccess);
        self::assertSame(1, $result->value()->id);
        self::assertSame(2, $result->value()->memberCount);
    }

    public function testRejectsUnknownGroupWithoutSaving(): void
    {
        $repository = $this->createMock(TontineGroupRepositoryInterface::class);
        $repository->expects(self::once())->method('find')->with(1)->willReturn(null);
        $repository->expects(self::never())->method('save');

        $useCase = new JoinTontineGroup($repository, $this->clock(), $this->logger());
        $result = $useCase(self::bob(), 1);

        self::assertFalse($result->isSuccess);
        self::assertSame(JoinTontineGroupError::GroupNotFound, $result->error());
    }

    public function testRejectsExistingMemberWithoutSaving(): void
    {
        $creator = self::alice();
        $group = self::makeGroup($creator);

        $repository = $this->createMock(TontineGroupRepositoryInterface::class);
        $repository->expects(self::once())->method('find')->with(1)->willReturn($group);
        $repository->expects(self::never())->method('save');

        $useCase = new JoinTontineGroup($repository, $this->clock(), $this->logger());
        $result = $useCase($creator, 1);

        self::assertFalse($result->isSuccess);
        self::assertSame(JoinTontineGroupError::AlreadyMember, $result->error());
    }

    public function testRejectsClosedGroupWithoutSaving(): void
    {
        $group = self::makeGroup(self::alice());
        $group->close(new \DateTimeImmutable(self::NOW));

        $repository = $this->createMock(TontineGroupRepositoryInterface::class);
        $repository->expects(self::once())->method('find')->with(1)->willReturn($group);
        $repository->expects(self::never())->method('save');

        $useCase = new JoinTontineGroup($repository, $this->clock(), $this->logger());
        $result = $useCase(self::bob(), 1);

        self::assertFalse($result->isSuccess);
        self::assertSame(JoinTontineGroupError::GroupClosed, $result->error());
    }

    private static function makeGroup(User $creator): TontineGroup
    {
        return TontineGroup::create(
            $creator,
            'Tontine famille',
            new Money('25000000', new Currency('USDC')),
            Periodicity::Weekly,
            12,
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
    }

    private static function alice(): User
    {
        return User::registerFromPrivy('did:privy:alice', 'alice@example.com', new WalletAddress('0x1111111111111111111111111111111111111111'));
    }

    private static function bob(): User
    {
        return User::registerFromPrivy('did:privy:bob', 'bob@example.com', new WalletAddress('0x2222222222222222222222222222222222222222'));
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
