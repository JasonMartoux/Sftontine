<?php

declare(strict_types=1);

namespace App\Tests\Application\Vault\UseCase;

use App\Application\Vault\Port\BlockchainReaderInterface;
use App\Application\Vault\Port\VaultAddressResolverInterface;
use App\Application\Vault\UseCase\VaultPositionReader;
use App\Domain\Identity\WalletAddress;
use PHPUnit\Framework\TestCase;

final class VaultPositionReaderTest extends TestCase
{
    private const VAULT_ADDRESS = '0x8C60503C0353ED12c3Eebc3036BF033A3BbB95Aa';
    private const FUND_MANAGER_ADDRESS = '0x904103dfE7231e2534e0Be29E6086CB0FF7d76bd';
    private const YIELD_POOL_ADDRESS = '0x1111111111111111111111111111111111111111';
    private const GDA_FORWARDER_ADDRESS = '0x6DA13Bde224A05a288748d857b9e7DDEffd1dE08';
    private const WALLET = '0x2222222222222222222222222222222222222222';

    public function testActiveMemberWithAPosition(): void
    {
        $reader = $this->makeReader(
            shares: '1000000',
            principal: '1050000',
            maxDeposit: '1000000000000',
            yieldReceived: '1234567890000000',
            flowRate: '100000000000',
            connected: true,
            stableYieldRate: '300',
        );

        $position = $reader->read(new WalletAddress(self::WALLET));

        self::assertSame('1000000', (string) $position->shares);
        self::assertSame('1050000', $position->principal->getAmount());
        self::assertSame('USDC', $position->principal->getCurrency()->getCode());
        self::assertSame('1234567890000000', (string) $position->yieldReceived);
        self::assertSame('100000000000', (string) $position->flowRate);
        self::assertTrue($position->connected);
        self::assertFalse($position->paused);
        self::assertSame('300', (string) $position->aprBasisPoints);
    }

    public function testZeroMaxDepositMeansPaused(): void
    {
        $reader = $this->makeReader(
            shares: '0',
            principal: '0',
            maxDeposit: '0',
            yieldReceived: '0',
            flowRate: '0',
            connected: false,
            stableYieldRate: '300',
        );

        $position = $reader->read(new WalletAddress(self::WALLET));

        self::assertTrue($position->paused);
    }

    public function testDisconnectedMemberStillReflectsAccumulatedYield(): void
    {
        $reader = $this->makeReader(
            shares: '500000',
            principal: '500000',
            maxDeposit: '1000000000000',
            yieldReceived: '999999999999',
            flowRate: '50000000000',
            connected: false,
            stableYieldRate: '300',
        );

        $position = $reader->read(new WalletAddress(self::WALLET));

        self::assertFalse($position->connected);
        self::assertSame('999999999999', (string) $position->yieldReceived);
        self::assertFalse($position->paused);
    }

    public function testNegativeFlowRateIsHandledCorrectly(): void
    {
        $reader = $this->makeReader(
            shares: '1000000',
            principal: '1000000',
            maxDeposit: '1000000000000',
            yieldReceived: '0',
            flowRate: '-100000000000',
            connected: true,
            stableYieldRate: '300',
        );

        $position = $reader->read(new WalletAddress(self::WALLET));

        self::assertSame('-100000000000', (string) $position->flowRate);
    }

    private function makeReader(
        string $shares,
        string $principal,
        string $maxDeposit,
        string $yieldReceived,
        string $flowRate,
        bool $connected,
        string $stableYieldRate,
    ): VaultPositionReader {
        $blockchainReader = $this->createStub(BlockchainReaderInterface::class);
        $blockchainReader->method('call')->willReturnCallback(
            static function (string $to, string $signature, array $args = [], array $outputTypes = ['uint256']) use (
                $shares,
                $principal,
                $maxDeposit,
                $yieldReceived,
                $flowRate,
                $connected,
                $stableYieldRate,
            ): array {
                return match ($signature) {
                    'balanceOf(address)' => [$shares],
                    'previewRedeem(uint256)' => [$principal],
                    'maxDeposit(address)' => [$maxDeposit],
                    'getTotalAmountReceivedByMember(address)' => [$yieldReceived],
                    'getMemberFlowRate(address)' => [$flowRate],
                    'isMemberConnected(address,address)' => [$connected],
                    'stableYieldRate()' => [$stableYieldRate],
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
