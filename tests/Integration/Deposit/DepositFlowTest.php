<?php

declare(strict_types=1);

namespace App\Tests\Integration\Deposit;

use App\Application\Deposit\UseCase\ConfirmDepositTransaction;
use App\Application\Vault\Port\BlockchainReaderInterface;
use App\Application\Vault\Port\VaultAddressResolverInterface;
use App\Domain\Deposit\DepositTransactionStatus;
use App\Domain\Deposit\TransactionHash;
use App\Domain\Identity\WalletAddress;
use App\Tests\Integration\Deposit\Support\AbiEncoder;
use App\Tests\Integration\Deposit\Support\AnvilTestClient;
use kornrunner\Keccak;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Exercises the real deposit flow (approve -> deposit -> connectPool -> confirm) against a
 * live Anvil fork of Base mainnet — see `make anvil-up` / `make test-integration`. The test
 * wallet needs no private key: Anvil lets an impersonated account submit unsigned
 * `eth_sendTransaction` calls, and its USDC balance is set directly via the `anvil_setStorageAt`
 * cheat code (brute-forcing the mapping slot rather than assuming a specific storage layout,
 * so this keeps working if the token's implementation changes).
 */
#[Group('integration')]
final class DepositFlowTest extends KernelTestCase
{
    private const TEST_WALLET = '0x1234567890123456789012345678901234567890';
    private const DEPOSIT_AMOUNT = '1000000000'; // 1,000 USDC (6-dec minor units)
    private const HUNDRED_ETH_WEI_HEX = '0x56BC75E2D63100000';

    public function testApproveDepositAndConnectPoolIsConfirmedFromItsReceipt(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $rpcUrl = $this->requiredEnv('BASE_RPC_URL');
        $vaultAddress = $this->requiredEnv('VAULT_ADDRESS');
        $usdcAddress = $this->requiredEnv('USDC_ADDRESS');
        $gdaForwarderAddress = $this->requiredEnv('GDA_FORWARDER_ADDRESS');

        $blockchainReader = $container->get(BlockchainReaderInterface::class);
        $addressResolver = $container->get(VaultAddressResolverInterface::class);
        $confirmDepositTransaction = $container->get(ConfirmDepositTransaction::class);

        \assert($blockchainReader instanceof BlockchainReaderInterface);
        \assert($addressResolver instanceof VaultAddressResolverInterface);
        \assert($confirmDepositTransaction instanceof ConfirmDepositTransaction);

        $anvil = new AnvilTestClient(HttpClient::create(), $rpcUrl);

        $anvil->setBalance(self::TEST_WALLET, self::HUNDRED_ETH_WEI_HEX);
        $this->fundUsdc($anvil, $blockchainReader, $usdcAddress, self::TEST_WALLET, self::DEPOSIT_AMOUNT);

        $anvil->impersonateAccount(self::TEST_WALLET);

        $anvil->sendTransaction(
            self::TEST_WALLET,
            $usdcAddress,
            AbiEncoder::approve($vaultAddress, self::DEPOSIT_AMOUNT),
        );

        $depositTxHash = $anvil->sendTransaction(
            self::TEST_WALLET,
            $vaultAddress,
            AbiEncoder::deposit(self::DEPOSIT_AMOUNT, self::TEST_WALLET),
        );

        $anvil->sendTransaction(
            self::TEST_WALLET,
            $gdaForwarderAddress,
            AbiEncoder::connectPool($addressResolver->yieldPoolAddress()),
        );

        $anvil->stopImpersonatingAccount(self::TEST_WALLET);

        $depositTransaction = ($confirmDepositTransaction)(
            new WalletAddress(self::TEST_WALLET),
            new TransactionHash($depositTxHash),
        );

        self::assertSame(DepositTransactionStatus::Confirmed, $depositTransaction->status);
        self::assertSame(self::DEPOSIT_AMOUNT, $depositTransaction->amount()?->getAmount());
    }

    /**
     * Brute-forces the ERC-20 `balanceOf` mapping's storage slot (tries slots 0-9, the range
     * covering virtually every real-world implementation) rather than assuming one, then
     * writes the target balance there directly via `anvil_setStorageAt`.
     */
    private function fundUsdc(
        AnvilTestClient $anvil,
        BlockchainReaderInterface $blockchainReader,
        string $usdcAddress,
        string $wallet,
        string $amount,
    ): void {
        $addressWord = AbiEncoder::addressWord($wallet);
        $amountWord = AbiEncoder::uintWord($amount);
        $zeroWord = str_repeat('0', 64);

        for ($slot = 0; $slot < 10; ++$slot) {
            $slotWord = str_pad(dechex($slot), 64, '0', \STR_PAD_LEFT);
            $storageKey = '0x'.Keccak::hash((string) hex2bin($addressWord.$slotWord), 256);

            $anvil->setStorageAt($usdcAddress, $storageKey, '0x'.$amountWord);
            [$balance] = $blockchainReader->call($usdcAddress, 'balanceOf(address)', [$wallet], ['uint256']);

            if ($balance === $amount) {
                return;
            }

            $anvil->setStorageAt($usdcAddress, $storageKey, '0x'.$zeroWord);
        }

        throw new \RuntimeException('Could not locate the USDC balanceOf storage slot (tried slots 0-9).');
    }

    private function requiredEnv(string $name): string
    {
        // Real process env vars (set on the Makefile's test-integration invocation) show up in
        // getenv(); .env-file values are Dotenv-loaded into $_SERVER/$_ENV without putenv().
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        if (!\is_string($value) || '' === $value) {
            throw new \RuntimeException(\sprintf('Missing required environment variable "%s" for the integration test.', $name));
        }

        return $value;
    }
}
