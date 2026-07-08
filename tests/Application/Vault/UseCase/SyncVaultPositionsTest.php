<?php

declare(strict_types=1);

namespace App\Tests\Application\Vault\UseCase;

use App\Application\Identity\Port\UserRepositoryInterface;
use App\Application\Tontine\Port\TontineGroupRepositoryInterface;
use App\Application\Vault\Exception\BlockchainCallException;
use App\Application\Vault\Port\BlockchainReaderInterface;
use App\Application\Vault\Port\VaultAddressResolverInterface;
use App\Application\Vault\Port\YieldSnapshotRepositoryInterface;
use App\Application\Vault\UseCase\SyncVaultPositions;
use App\Application\Vault\UseCase\VaultPositionReader;
use App\Domain\Identity\User;
use App\Domain\Identity\WalletAddress;
use App\Domain\Tontine\Periodicity;
use App\Domain\Tontine\TontineGroup;
use App\Domain\Vault\VaultPosition;
use BcMath\Number;
use Money\Currency;
use Money\Money;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SyncVaultPositionsTest extends TestCase
{
    private const USER_WALLET = '0x1111111111111111111111111111111111111111';
    private const SAFE_ADDRESS = '0x4444444444444444444444444444444444444444';
    private const VAULT_ADDRESS = '0x8C60503C0353ED12c3Eebc3036BF033A3BbB95Aa';
    private const GDA_FORWARDER_ADDRESS = '0x6DA13Bde224A05a288748d857b9e7DDEffd1dE08';
    private const FUND_MANAGER_ADDRESS = '0x904103dfE7231e2534e0Be29E6086CB0FF7d76bd';
    private const YIELD_POOL_ADDRESS = '0x1111111111111111111111111111111111111111';

    public function testSyncsEveryUserAndEveryGroupWithASafe(): void
    {
        $user = User::registerFromPrivy('did:privy:alice', 'alice@example.com', new WalletAddress(self::USER_WALLET));
        $group = TontineGroup::create($user, 'Tontine famille', new Money('25000000', new Currency('USDC')), Periodicity::Weekly, 12, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $group->provisionSafe(new WalletAddress(self::SAFE_ADDRESS));

        $userRepository = $this->createStub(UserRepositoryInterface::class);
        $userRepository->method('findAll')->willReturn([$user]);

        $groups = $this->createStub(TontineGroupRepositoryInterface::class);
        $groups->method('findAllWithSafeAddress')->willReturn([$group]);

        $positionReader = $this->makePositionReader();

        $snapshots = $this->createMock(YieldSnapshotRepositoryInterface::class);
        $snapshots->expects(self::exactly(2))->method('save');

        (new SyncVaultPositions($userRepository, $groups, $positionReader, $snapshots, new NullLogger()))();
    }

    public function testOneGroupsRpcFailureDoesNotAbortTheOthers(): void
    {
        $user = User::registerFromPrivy('did:privy:alice', 'alice@example.com', new WalletAddress(self::USER_WALLET));
        $failingGroup = TontineGroup::create($user, 'Tontine A', new Money('25000000', new Currency('USDC')), Periodicity::Weekly, 12, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $failingGroup->provisionSafe(new WalletAddress(self::SAFE_ADDRESS));
        $succeedingGroup = TontineGroup::create($user, 'Tontine B', new Money('25000000', new Currency('USDC')), Periodicity::Weekly, 12, new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $succeedingGroup->provisionSafe(new WalletAddress('0x5555555555555555555555555555555555555555'));

        $userRepository = $this->createStub(UserRepositoryInterface::class);
        $userRepository->method('findAll')->willReturn([]);

        $groups = $this->createStub(TontineGroupRepositoryInterface::class);
        $groups->method('findAllWithSafeAddress')->willReturn([$failingGroup, $succeedingGroup]);

        $positionReader = $this->makePositionReaderWithFailure();

        $snapshots = $this->createMock(YieldSnapshotRepositoryInterface::class);
        $snapshots->expects(self::once())->method('save');

        (new SyncVaultPositions($userRepository, $groups, $positionReader, $snapshots, new NullLogger()))();
    }

    private function makePositionReader(): VaultPositionReader
    {
        $blockchainReader = $this->createStub(BlockchainReaderInterface::class);
        $blockchainReader->method('call')->willReturnCallback(
            static function (string $to, string $signature): array {
                return match ($signature) {
                    'balanceOf(address)' => ['0'],
                    'previewRedeem(uint256)' => ['0'],
                    'maxDeposit(address)' => ['1000000000000'],
                    'getTotalAmountReceivedByMember(address)' => ['0'],
                    'getMemberFlowRate(address)' => ['0'],
                    'isMemberConnected(address,address)' => [false],
                    'stableYieldRate()' => ['500'],
                    default => throw new \LogicException("Unexpected call: {$signature} on {$to}"),
                };
            },
        );

        $addressResolver = $this->createStub(VaultAddressResolverInterface::class);
        $addressResolver->method('fundManagerAddress')->willReturn(self::FUND_MANAGER_ADDRESS);
        $addressResolver->method('yieldPoolAddress')->willReturn(self::YIELD_POOL_ADDRESS);

        return new VaultPositionReader(
            blockchainReader: $blockchainReader,
            addressResolver: $addressResolver,
            vaultAddress: self::VAULT_ADDRESS,
            gdaForwarderAddress: self::GDA_FORWARDER_ADDRESS,
        );
    }

    private function makePositionReaderWithFailure(): VaultPositionReader
    {
        $blockchainReader = $this->createStub(BlockchainReaderInterface::class);
        $blockchainReader->method('call')->willReturnCallback(
            static function (string $to, string $signature, array $args = []): array {
                // Simulate failure for SAFE_ADDRESS
                if (!empty($args) && $args[0] === self::SAFE_ADDRESS) {
                    throw BlockchainCallException::rpcError('timeout');
                }
                return match ($signature) {
                    'balanceOf(address)' => ['0'],
                    'previewRedeem(uint256)' => ['0'],
                    'maxDeposit(address)' => ['1000000000000'],
                    'getTotalAmountReceivedByMember(address)' => ['0'],
                    'getMemberFlowRate(address)' => ['0'],
                    'isMemberConnected(address,address)' => [false],
                    'stableYieldRate()' => ['500'],
                    default => throw new \LogicException("Unexpected call: {$signature} on {$to}"),
                };
            },
        );

        $addressResolver = $this->createStub(VaultAddressResolverInterface::class);
        $addressResolver->method('fundManagerAddress')->willReturn(self::FUND_MANAGER_ADDRESS);
        $addressResolver->method('yieldPoolAddress')->willReturn(self::YIELD_POOL_ADDRESS);

        return new VaultPositionReader(
            blockchainReader: $blockchainReader,
            addressResolver: $addressResolver,
            vaultAddress: self::VAULT_ADDRESS,
            gdaForwarderAddress: self::GDA_FORWARDER_ADDRESS,
        );
    }
}
