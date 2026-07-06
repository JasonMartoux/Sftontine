<?php

declare(strict_types=1);

namespace App\Tests\Integration\Deposit\Support;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin JSON-RPC client for the Anvil "cheat code" methods (impersonation, storage/balance
 * overrides) used to fund and drive a test wallet on the forked chain without ever needing a
 * private key — impersonated accounts can submit unsigned `eth_sendTransaction` calls.
 */
final readonly class AnvilTestClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $rpcUrl,
    ) {
    }

    public function impersonateAccount(string $address): void
    {
        $this->call('anvil_impersonateAccount', [$address]);
    }

    public function stopImpersonatingAccount(string $address): void
    {
        $this->call('anvil_stopImpersonatingAccount', [$address]);
    }

    public function setBalance(string $address, string $weiHex): void
    {
        $this->call('anvil_setBalance', [$address, $weiHex]);
    }

    public function setStorageAt(string $address, string $slotHex, string $valueHex): void
    {
        $this->call('anvil_setStorageAt', [$address, $slotHex, $valueHex]);
    }

    /**
     * `deposit`/`connectPool` do substantial internal work (Superfluid stream setup, macro
     * forwarding, FundManager accounting — see config/abi/README.md) and need far more than a
     * plain ERC-20 `approve`; 10M gas stays comfortably under Anvil's forked block gas limit.
     */
    public function sendTransaction(string $from, string $to, string $data): string
    {
        $hash = $this->call('eth_sendTransaction', [[
            'from' => $from,
            'to' => $to,
            'data' => $data,
            'gas' => '0x989680', // 10,000,000
        ]]);
        \assert(\is_string($hash));

        return $hash;
    }

    /**
     * @param list<mixed> $params
     */
    private function call(string $method, array $params = []): mixed
    {
        $response = $this->httpClient->request('POST', $this->rpcUrl, [
            'json' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params],
        ]);

        $payload = $response->toArray(false);

        if (isset($payload['error'])) {
            throw new \RuntimeException(\sprintf('Anvil RPC error on %s: %s', $method, json_encode($payload['error'], \JSON_THROW_ON_ERROR)));
        }

        return $payload['result'] ?? null;
    }
}
