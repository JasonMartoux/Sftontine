<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Gateways;

use App\Application\Vault\Exception\BlockchainCallException;
use App\Infrastructure\Gateways\EthCallBlockchainReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Exercises the real ABI encode/decode logic (selectors, word packing, two's complement)
 * against a mocked JSON-RPC transport — no real node needed, but the HTTP contract (request
 * shape, response parsing) is genuine, unlike the mocked-port unit tests for VaultPositionReader.
 */
final class EthCallBlockchainReaderTest extends TestCase
{
    private const CONTRACT = '0x8C60503C0353ED12c3Eebc3036BF033A3BbB95Aa';
    private const WALLET = '0x51B5B6f4768927E4029A210aa55f167211a319A7';

    public function testEncodesSelectorAndAddressArgumentThenDecodesUint256(): void
    {
        $capturedBody = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedBody): MockResponse {
            $capturedBody = $options['body'];

            return new MockResponse(json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => '0x'.str_pad('3e8', 64, '0', \STR_PAD_LEFT),
            ], \JSON_THROW_ON_ERROR));
        });

        $reader = new EthCallBlockchainReader($client, 'https://rpc.example/');
        $result = $reader->call(self::CONTRACT, 'balanceOf(address)', [self::WALLET], ['uint256']);

        self::assertSame(['1000'], $result);

        self::assertIsString($capturedBody);
        $payload = json_decode($capturedBody, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('eth_call', $payload['method']);

        $params = $payload['params'];
        self::assertIsArray($params);
        $callObject = $params[0];
        self::assertIsArray($callObject);
        $data = $callObject['data'];
        self::assertIsString($data);
        // Real ERC-20 `balanceOf(address)` selector, independently verifiable (e.g. any block explorer).
        self::assertStringStartsWith('0x70a08231', $data);
        // Address argument, left-padded to a 32-byte word, no 0x prefix.
        self::assertStringEndsWith(strtolower(substr(self::WALLET, 2)), $data);
        self::assertSame('latest', $params[1]);
    }

    public function testDecodesAddressOutput(): void
    {
        $client = new MockHttpClient(static fn () => new MockResponse(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => '0x'.str_pad(strtolower(substr(self::WALLET, 2)), 64, '0', \STR_PAD_LEFT),
        ], \JSON_THROW_ON_ERROR)));

        $reader = new EthCallBlockchainReader($client, 'https://rpc.example/');
        [$address] = $reader->call(self::CONTRACT, 'FUND_MANAGER()', [], ['address']);

        self::assertSame(strtolower(self::WALLET), $address);
    }

    public function testDecodesBoolOutputs(): void
    {
        $trueClient = new MockHttpClient(static fn () => new MockResponse(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => '0x'.str_pad('1', 64, '0', \STR_PAD_LEFT),
        ], \JSON_THROW_ON_ERROR)));
        $reader = new EthCallBlockchainReader($trueClient, 'https://rpc.example/');
        [$connected] = $reader->call(self::CONTRACT, 'isMemberConnected(address,address)', [], ['bool']);
        self::assertTrue($connected);

        $falseClient = new MockHttpClient(static fn () => new MockResponse(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => '0x'.str_repeat('0', 64),
        ], \JSON_THROW_ON_ERROR)));
        $reader = new EthCallBlockchainReader($falseClient, 'https://rpc.example/');
        [$notConnected] = $reader->call(self::CONTRACT, 'isMemberConnected(address,address)', [], ['bool']);
        self::assertFalse($notConnected);
    }

    public function testDecodesNegativeInt96ViaTwosComplement(): void
    {
        // Two's complement, full 256-bit word, for decimal -100000000000 (a receiving-side
        // outflow on the GDA pool) — the EVM sign-extends signed values to the whole word.
        $word = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffe8b7891800';
        $word = substr($word, -64);

        $client = new MockHttpClient(static fn () => new MockResponse(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => '0x'.$word,
        ], \JSON_THROW_ON_ERROR)));

        $reader = new EthCallBlockchainReader($client, 'https://rpc.example/');
        [$flowRate] = $reader->call(self::CONTRACT, 'getMemberFlowRate(address)', [self::WALLET], ['int96']);

        self::assertSame('-100000000000', $flowRate);
    }

    public function testJsonRpcErrorFieldIsTranslatedToDomainException(): void
    {
        $client = new MockHttpClient(static fn () => new MockResponse(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => ['code' => -32000, 'message' => 'execution reverted'],
        ], \JSON_THROW_ON_ERROR)));

        $reader = new EthCallBlockchainReader($client, 'https://rpc.example/');

        $this->expectException(BlockchainCallException::class);
        $this->expectExceptionMessage('Base RPC error: execution reverted');

        $reader->call(self::CONTRACT, 'balanceOf(address)', [self::WALLET]);
    }

    public function testMissingResultFieldIsTranslatedToDomainException(): void
    {
        $client = new MockHttpClient(static fn () => new MockResponse(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
        ], \JSON_THROW_ON_ERROR)));

        $reader = new EthCallBlockchainReader($client, 'https://rpc.example/');

        $this->expectException(BlockchainCallException::class);
        $this->expectExceptionMessage('Malformed eth_call response: Missing "result" field in eth_call response.');

        $reader->call(self::CONTRACT, 'balanceOf(address)', [self::WALLET]);
    }

    public function testTransportFailureIsTranslatedToDomainException(): void
    {
        $client = new MockHttpClient(static fn () => throw new TransportException('connection refused'));

        $reader = new EthCallBlockchainReader($client, 'https://rpc.example/');

        $this->expectException(BlockchainCallException::class);
        $this->expectExceptionMessage('Base RPC error: connection refused');

        $reader->call(self::CONTRACT, 'balanceOf(address)', [self::WALLET]);
    }
}
