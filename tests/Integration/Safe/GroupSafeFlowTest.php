<?php

declare(strict_types=1);

namespace App\Tests\Integration\Safe;

use App\Application\Identity\Port\UserRepositoryInterface;
use App\Application\Safe\UseCase\ConfirmGroupSafeDeployment;
use App\Application\Safe\UseCase\ConfirmGroupSafeExecution;
use App\Application\Tontine\Port\TontineGroupRepositoryInterface;
use App\Application\Vault\Port\BlockchainReaderInterface;
use App\Domain\Deposit\TransactionHash;
use App\Domain\Identity\User;
use App\Domain\Identity\WalletAddress;
use App\Domain\Safe\SafeTransactionPurpose;
use App\Domain\Tontine\Periodicity;
use App\Domain\Tontine\TontineGroup;
use App\Tests\Integration\Deposit\Support\AbiEncoder;
use App\Tests\Integration\Deposit\Support\AnvilTestClient;
use Money\Currency;
use Money\Money;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Exercises the full group-Safe flow (deploy -> confirm -> connectPool via execTransaction ->
 * confirm) against a live Anvil fork of Base mainnet — see `make anvil-up` /
 * `make test-integration`. Unlike the rest of the integration suite, signing the Safe's
 * execTransaction requires a *real* private key (a raw ECDSA signature over the Safe's EIP-712
 * digest, which Anvil's unsigned-tx impersonation trick cannot produce) — this test uses one of
 * Anvil's well-known default dev-account private keys, a deliberate, contained exception scoped
 * to this one test.
 */
#[Group('integration')]
final class GroupSafeFlowTest extends KernelTestCase
{
    // Anvil's default account #0 — well-known, funded automatically by anvil --fork-url,
    // never used outside this integration test.
    private const ADMIN_PRIVATE_KEY = '0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80'; // gitleaks:allow
    private const ADMIN_WALLET = '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266';

    public function testDeploysConfirmsAndConnectsTheGroupSafeToTheYieldPool(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $rpcUrl = $this->requiredEnv('BASE_RPC_URL');
        $proxyFactoryAddress = $this->requiredEnv('SAFE_PROXY_FACTORY_ADDRESS');
        $safeSingletonAddress = $this->requiredEnv('SAFE_SINGLETON_ADDRESS');
        $fallbackHandlerAddress = $this->requiredEnv('SAFE_FALLBACK_HANDLER_ADDRESS');
        $gdaForwarderAddress = $this->requiredEnv('GDA_FORWARDER_ADDRESS');
        $vaultAddress = $this->requiredEnv('VAULT_ADDRESS');

        $users = $container->get(UserRepositoryInterface::class);
        $groups = $container->get(TontineGroupRepositoryInterface::class);
        $confirmDeployment = $container->get(ConfirmGroupSafeDeployment::class);
        $confirmExecution = $container->get(ConfirmGroupSafeExecution::class);
        \assert($users instanceof UserRepositoryInterface);
        \assert($groups instanceof TontineGroupRepositoryInterface);
        \assert($confirmDeployment instanceof ConfirmGroupSafeDeployment);
        \assert($confirmExecution instanceof ConfirmGroupSafeExecution);

        $admin = User::registerFromPrivy('did:privy:safe-admin', 'safe-admin@example.com', new WalletAddress(self::ADMIN_WALLET));
        $users->save($admin);
        $group = TontineGroup::create($admin, 'Tontine intégration', new Money('25000000', new Currency('USDC')), Periodicity::Weekly, 12, new \DateTimeImmutable());
        $groups->save($group);
        \assert(null !== $group->id);

        $anvil = new AnvilTestClient(HttpClient::create(), $rpcUrl);
        $anvil->setBalance(self::ADMIN_WALLET, '0x56BC75E2D63100000');
        $anvil->impersonateAccount(self::ADMIN_WALLET);

        // 1. Deploy the Safe via createProxyWithNonce, owner = the admin's wallet.
        $initializer = AbiEncoder::encodeDynamic('setup(address[],uint256,address,bytes,address,address,uint256,address)', [
            ['type' => 'address[]', 'value' => [self::ADMIN_WALLET]],
            ['type' => 'uint256', 'value' => '1'],
            ['type' => 'address', 'value' => '0x0000000000000000000000000000000000000000'],
            ['type' => 'bytes', 'value' => ''],
            ['type' => 'address', 'value' => $fallbackHandlerAddress],
            ['type' => 'address', 'value' => '0x0000000000000000000000000000000000000000'],
            ['type' => 'uint256', 'value' => '0'],
            ['type' => 'address', 'value' => '0x0000000000000000000000000000000000000000'],
        ]);
        $deployData = AbiEncoder::encodeDynamic('createProxyWithNonce(address,bytes,uint256)', [
            ['type' => 'address', 'value' => $safeSingletonAddress],
            ['type' => 'bytes', 'value' => $initializer],
            ['type' => 'uint256', 'value' => (string) $group->id],
        ]);

        $deployTxHash = $anvil->sendTransaction(self::ADMIN_WALLET, $proxyFactoryAddress, $deployData);
        $this->waitForReceipt($rpcUrl, $deployTxHash);

        $deployResult = ($confirmDeployment)($admin, $group->id, new TransactionHash($deployTxHash));
        self::assertTrue($deployResult->isSuccess);
        self::assertNotNull($deployResult->value()->safeAddress);

        $reloadedGroup = $groups->find($group->id);
        self::assertNotNull($reloadedGroup);
        self::assertTrue($reloadedGroup->hasSafe());

        $safeAddress = $deployResult->value()->safeAddress;

        // 2. Funding the Safe with USDC is out of scope for this test (Task 19 only covers
        //    deploy + connectPool) — no funding needed to exercise execTransaction for
        //    connectPool, which moves no funds.

        // 3. Resolve the yield pool address the same way the frontend does.
        $blockchainReader = $container->get(BlockchainReaderInterface::class);
        \assert($blockchainReader instanceof BlockchainReaderInterface);
        [$fundManagerAddress] = $blockchainReader->call($vaultAddress, 'FUND_MANAGER()', [], ['address']);
        \assert(\is_string($fundManagerAddress));
        [$yieldPoolAddress] = $blockchainReader->call($fundManagerAddress, 'YIELD_POOL()', [], ['address']);
        \assert(\is_string($yieldPoolAddress));

        // 4. Build, sign (real private key — see class docblock), and execute connectPool via
        //    the Safe's execTransaction.
        $connectPoolData = AbiEncoder::encodeDynamic('connectPool(address,bytes)', [
            ['type' => 'address', 'value' => $yieldPoolAddress],
            ['type' => 'bytes', 'value' => ''],
        ]);

        [$nonce] = $blockchainReader->call($safeAddress, 'nonce()', [], ['uint256']);
        \assert(\is_string($nonce));

        $safeTx = $this->computeAndSignSafeTransaction(
            $rpcUrl,
            $safeAddress,
            $gdaForwarderAddress,
            $connectPoolData,
            $nonce,
            self::ADMIN_PRIVATE_KEY,
        );

        $execData = AbiEncoder::encodeDynamic('execTransaction(address,uint256,bytes,uint8,uint256,uint256,uint256,address,address,bytes)', [
            ['type' => 'address', 'value' => $gdaForwarderAddress],
            ['type' => 'uint256', 'value' => '0'],
            ['type' => 'bytes', 'value' => $connectPoolData],
            ['type' => 'uint8', 'value' => '0'],
            ['type' => 'uint256', 'value' => '0'],
            ['type' => 'uint256', 'value' => '0'],
            ['type' => 'uint256', 'value' => '0'],
            ['type' => 'address', 'value' => '0x0000000000000000000000000000000000000000'],
            ['type' => 'address', 'value' => '0x0000000000000000000000000000000000000000'],
            ['type' => 'bytes', 'value' => $safeTx['signature']],
        ]);

        $execTxHash = $anvil->sendTransaction(self::ADMIN_WALLET, $safeAddress, $execData);
        $this->waitForReceipt($rpcUrl, $execTxHash);

        $executionResult = ($confirmExecution)($admin, $group->id, SafeTransactionPurpose::ConnectYieldPool, new TransactionHash($execTxHash), new TransactionHash($safeTx['hash']));
        self::assertTrue($executionResult->isSuccess);
        self::assertSame('confirmed', $executionResult->value()->status);
    }

    /**
     * Anvil's `eth_sendTransaction` can return a tx hash slightly before the block that mines
     * it is queryable via `eth_getTransactionReceipt` (observed directly: an immediate
     * follow-up call returned `null` even though the tx was mined moments later) — poll briefly
     * rather than assuming synchronous auto-mine, so `ConfirmGroupSafeDeployment`/
     * `ConfirmGroupSafeExecution`'s own single receipt check (no retry loop of its own,
     * by design — see Task 6/7) doesn't race it.
     */
    private function waitForReceipt(string $rpcUrl, string $txHash): void
    {
        $http = HttpClient::create();

        for ($attempt = 0; $attempt < 20; ++$attempt) {
            $response = $http->request('POST', $rpcUrl, [
                'json' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'eth_getTransactionReceipt', 'params' => [$txHash]],
            ]);

            if (null !== ($response->toArray(false)['result'] ?? null)) {
                return;
            }

            usleep(100_000);
        }

        throw new \RuntimeException(\sprintf('Transaction %s was not mined within the expected time.', $txHash));
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

    /**
     * `getTransactionHash(address,uint256,bytes,uint8,uint256,uint256,uint256,address,address,uint256)`
     * has a dynamic `bytes data` parameter — `BlockchainReaderInterface::call()` only supports
     * static `address`/`uint256`-word arguments (by its own docblock's design, verified against
     * `EthCallBlockchainReader::encodeArgs()`), so it cannot be reused here without silently
     * mis-encoding the call. Build the calldata via `AbiEncoder::encodeDynamic()` (the same
     * dynamic-type encoder used for `execTransaction`/`createProxyWithNonce` above) and call it
     * directly instead.
     *
     * @return array{hash: string, signature: string}
     */
    private function computeAndSignSafeTransaction(
        string $rpcUrl,
        string $safeAddress,
        string $to,
        string $data,
        string $nonce,
        string $privateKey,
    ): array {
        $callData = AbiEncoder::encodeDynamic('getTransactionHash(address,uint256,bytes,uint8,uint256,uint256,uint256,address,address,uint256)', [
            ['type' => 'address', 'value' => $to],
            ['type' => 'uint256', 'value' => '0'],
            ['type' => 'bytes', 'value' => $data],
            ['type' => 'uint8', 'value' => '0'],
            ['type' => 'uint256', 'value' => '0'],
            ['type' => 'uint256', 'value' => '0'],
            ['type' => 'uint256', 'value' => '0'],
            ['type' => 'address', 'value' => '0x0000000000000000000000000000000000000000'],
            ['type' => 'address', 'value' => '0x0000000000000000000000000000000000000000'],
            ['type' => 'uint256', 'value' => $nonce],
        ]);

        $response = HttpClient::create()->request('POST', $rpcUrl, [
            'json' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'eth_call', 'params' => [['to' => $safeAddress, 'data' => $callData], 'latest']],
        ]);
        $result = $response->toArray(false)['result'] ?? null;
        \assert(\is_string($result));

        // getTransactionHash() returns a single bytes32 — the raw eth_call result is already
        // exactly that word, no offset/length decoding needed (it's not a dynamic return type).
        $hashHex = '0x'.substr($result, 2, 64);

        // secp256k1 sign over the raw 32-byte digest (v in {27,28}) — this is exactly what
        // Safe's checkNSignatures expects for a direct-hash (non-eth_sign) signature, and
        // exactly what an EIP-712 signTypedData signature would produce, since
        // getTransactionHash() IS the final EIP-712 digest.
        $signature = AbiEncoder::signDigest($hashHex, $privateKey);

        return ['hash' => $hashHex, 'signature' => $signature];
    }
}
