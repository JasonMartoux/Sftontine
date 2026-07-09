<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Gateways;

use App\Application\Vault\Exception\BlockchainCallException;
use App\Domain\Deposit\TransactionHash;
use App\Infrastructure\Gateways\EthSafeReceiptReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class EthSafeReceiptReaderTest extends TestCase
{
    private const PROXY_FACTORY = '0x6666666666666666666666666666666666666666';
    private const PROXY_ADDRESS = '0x4444444444444444444444444444444444444444';
    private const SAFE_ADDRESS = '0x4444444444444444444444444444444444444444';

    // keccak256("ProxyCreation(address,address)") — independently verified via
    // kornrunner/keccak inside the container.
    private const PROXY_CREATION_TOPIC0 = '0x4f51faf6c4561ff95f067657e43439f0f856d97c04d9ec9070a6199ad418e235';
    // keccak256("ExecutionSuccess(bytes32,uint256)") — independently verified;
    // corrected from the brief's draft, which had a typo in the last hex nibble
    // (...556c instead of the correct ...556e).
    private const EXECUTION_SUCCESS_TOPIC0 = '0x442e715f626346e8c54381002da614f62bee8d27386535b2521ec8540898556e';
    // keccak256("ExecutionFailure(bytes32,uint256)") — independently verified via
    // kornrunner/keccak inside the container.
    private const EXECUTION_FAILURE_TOPIC0 = '0x23428b18acfb3ea64b08dc0c1d296ea9c09702c09083ca5272e64d115b687d23';

    public function testDeploymentReceiptDecodesProxyCreation(): void
    {
        // ProxyCreation(address indexed proxy, address singleton) — proxy is indexed
        // (topics[1]), singleton is not (data). This reader only needs proxy.
        $log = [
            'address' => self::PROXY_FACTORY,
            'topics' => [
                self::PROXY_CREATION_TOPIC0,
                '0x'.str_pad(strtolower(substr(self::PROXY_ADDRESS, 2)), 64, '0', \STR_PAD_LEFT),
            ],
            'data' => '0x'.str_pad(strtolower('55'.str_repeat('5', 39)), 64, '0', \STR_PAD_LEFT),
        ];

        $reader = new EthSafeReceiptReader($this->clientReturning($this->receiptPayload('0x1', [$log])), 'https://rpc.example/', self::PROXY_FACTORY);
        $receipt = $reader->getDeploymentReceipt($this->txHash('a1'));

        self::assertNotNull($receipt);
        self::assertTrue($receipt->success);
        self::assertSame(strtolower(self::PROXY_ADDRESS), strtolower((string) $receipt->proxyAddress));
    }

    public function testFailedDeploymentTransactionHasNoProxyAddress(): void
    {
        $reader = new EthSafeReceiptReader($this->clientReturning($this->receiptPayload('0x0', [])), 'https://rpc.example/', self::PROXY_FACTORY);
        $receipt = $reader->getDeploymentReceipt($this->txHash('a1'));

        self::assertNotNull($receipt);
        self::assertFalse($receipt->success);
        self::assertNull($receipt->proxyAddress);
    }

    public function testDeploymentNullResultMeansNotYetMined(): void
    {
        $reader = new EthSafeReceiptReader($this->clientReturning(['jsonrpc' => '2.0', 'id' => 1, 'result' => null]), 'https://rpc.example/', self::PROXY_FACTORY);

        self::assertNull($reader->getDeploymentReceipt($this->txHash('a1')));
    }

    public function testExecutionReceiptMatchesExecutionSuccess(): void
    {
        // ExecutionSuccess(bytes32 indexed txHash, uint256 payment) — txHash is indexed
        // (topics[1], the full bytes32 word, no slicing needed), payment is not (data).
        $safeTxHash = $this->txHash('b2');
        $log = [
            'address' => self::SAFE_ADDRESS,
            'topics' => [self::EXECUTION_SUCCESS_TOPIC0, $safeTxHash->value],
            'data' => '0x'.str_pad('0', 64, '0', \STR_PAD_LEFT),
        ];

        $reader = new EthSafeReceiptReader($this->clientReturning($this->receiptPayload('0x1', [$log])), 'https://rpc.example/', self::PROXY_FACTORY);
        $receipt = $reader->getExecutionReceipt($this->txHash('a1'), $safeTxHash);

        self::assertNotNull($receipt);
        self::assertTrue($receipt->success);
    }

    public function testExecutionReceiptMatchesExecutionFailure(): void
    {
        $safeTxHash = $this->txHash('b2');
        $log = [
            'address' => self::SAFE_ADDRESS,
            'topics' => [self::EXECUTION_FAILURE_TOPIC0, $safeTxHash->value],
            'data' => '0x'.str_pad('0', 64, '0', \STR_PAD_LEFT),
        ];

        $reader = new EthSafeReceiptReader($this->clientReturning($this->receiptPayload('0x1', [$log])), 'https://rpc.example/', self::PROXY_FACTORY);
        $receipt = $reader->getExecutionReceipt($this->txHash('a1'), $safeTxHash);

        self::assertNotNull($receipt);
        self::assertFalse($receipt->success);
    }

    public function testExecutionReceiptForADifferentSafeTxHashIsIgnored(): void
    {
        $log = [
            'address' => self::SAFE_ADDRESS,
            'topics' => [self::EXECUTION_SUCCESS_TOPIC0, $this->txHash('c3')->value],
            'data' => '0x'.str_pad('0', 64, '0', \STR_PAD_LEFT),
        ];

        $reader = new EthSafeReceiptReader($this->clientReturning($this->receiptPayload('0x1', [$log])), 'https://rpc.example/', self::PROXY_FACTORY);

        self::assertNull($reader->getExecutionReceipt($this->txHash('a1'), $this->txHash('b2')));
    }

    public function testJsonRpcErrorFieldIsTranslatedToDomainException(): void
    {
        $reader = new EthSafeReceiptReader(
            $this->clientReturning(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32000, 'message' => 'not found']]),
            'https://rpc.example/',
            self::PROXY_FACTORY,
        );

        $this->expectException(BlockchainCallException::class);
        $reader->getDeploymentReceipt($this->txHash('a1'));
    }

    /**
     * @param list<array{address: string, topics: list<string>, data: string}> $logs
     *
     * @return array{jsonrpc: string, id: int, result: array{status: string, logs: list<array{address: string, topics: list<string>, data: string}>}}
     */
    private function receiptPayload(string $status, array $logs): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['status' => $status, 'logs' => $logs]];
    }

    private function txHash(string $pair): TransactionHash
    {
        return new TransactionHash('0x'.str_repeat($pair, 32));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function clientReturning(array $payload): MockHttpClient
    {
        return new MockHttpClient(static fn () => new MockResponse(json_encode($payload, \JSON_THROW_ON_ERROR)));
    }
}
