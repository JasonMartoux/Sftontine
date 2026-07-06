<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Gateways;

use App\Application\Vault\Exception\BlockchainCallException;
use App\Domain\Deposit\TransactionHash;
use App\Infrastructure\Gateways\EthReceiptReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class EthReceiptReaderTest extends TestCase
{
    private const VAULT = '0x8C60503C0353ED12c3Eebc3036BF033A3BbB95Aa';
    private const SENDER = '0x1111111111111111111111111111111111111111';
    private const OWNER = '0x2222222222222222222222222222222222222222';

    // keccak256("Deposit(address,address,uint256,uint256)") — independently verifiable.
    private const DEPOSIT_TOPIC0 = '0xdcbc1c05240f31ff3ad067ef1ee35ce4997762752e3a095284754544f4c709d7';

    private static function txHash(): TransactionHash
    {
        return new TransactionHash('0x'.str_repeat('a1', 32));
    }

    public function testSuccessfulReceiptWithMatchingDepositEventIsDecoded(): void
    {
        $reader = new EthReceiptReader(
            $this->clientReturning($this->receiptPayload(
                status: '0x1',
                logs: [$this->depositLog(assets: '1000000', shares: '1000000')],
            )),
            'https://rpc.example/',
            self::VAULT,
        );

        $receipt = $reader->getReceipt(self::txHash());

        self::assertNotNull($receipt);
        self::assertTrue($receipt->success);
        self::assertNotNull($receipt->depositEvent);
        self::assertSame(strtolower(self::SENDER), strtolower($receipt->depositEvent->sender));
        self::assertSame(strtolower(self::OWNER), strtolower($receipt->depositEvent->owner));
        self::assertSame('1000000', (string) $receipt->depositEvent->assets);
        self::assertSame('1000000', (string) $receipt->depositEvent->shares);
    }

    public function testFailedTransactionHasNoDepositEvent(): void
    {
        $reader = new EthReceiptReader(
            $this->clientReturning($this->receiptPayload(status: '0x0', logs: [])),
            'https://rpc.example/',
            self::VAULT,
        );

        $receipt = $reader->getReceipt(self::txHash());

        self::assertNotNull($receipt);
        self::assertFalse($receipt->success);
        self::assertNull($receipt->depositEvent);
    }

    public function testLogFromADifferentContractIsIgnored(): void
    {
        $otherContractLog = $this->depositLog(assets: '1000000', shares: '1000000');
        $otherContractLog['address'] = '0x9999999999999999999999999999999999999999';

        $reader = new EthReceiptReader(
            $this->clientReturning($this->receiptPayload(status: '0x1', logs: [$otherContractLog])),
            'https://rpc.example/',
            self::VAULT,
        );

        $receipt = $reader->getReceipt(self::txHash());

        self::assertNotNull($receipt);
        self::assertNull($receipt->depositEvent);
    }

    public function testNullResultMeansNotYetMined(): void
    {
        $reader = new EthReceiptReader(
            $this->clientReturning(['jsonrpc' => '2.0', 'id' => 1, 'result' => null]),
            'https://rpc.example/',
            self::VAULT,
        );

        $receipt = $reader->getReceipt(self::txHash());

        self::assertNull($receipt);
    }

    public function testJsonRpcErrorFieldIsTranslatedToDomainException(): void
    {
        $reader = new EthReceiptReader(
            $this->clientReturning(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32000, 'message' => 'not found']]),
            'https://rpc.example/',
            self::VAULT,
        );

        $this->expectException(BlockchainCallException::class);
        $this->expectExceptionMessage('Base RPC error: not found');

        $reader->getReceipt(self::txHash());
    }

    public function testTransportFailureIsTranslatedToDomainException(): void
    {
        $client = new MockHttpClient(static fn () => throw new TransportException('connection refused'));
        $reader = new EthReceiptReader($client, 'https://rpc.example/', self::VAULT);

        $this->expectException(BlockchainCallException::class);
        $this->expectExceptionMessage('Base RPC error: connection refused');

        $reader->getReceipt(self::txHash());
    }

    /**
     * @param list<array{address: string, topics: list<string>, data: string}> $logs
     *
     * @return array{jsonrpc: string, id: int, result: array{status: string, from: string, to: string, logs: list<array{address: string, topics: list<string>, data: string}>}}
     */
    private function receiptPayload(string $status, array $logs): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [
                'status' => $status,
                'from' => self::SENDER,
                'to' => self::VAULT,
                'logs' => $logs,
            ],
        ];
    }

    /**
     * @return array{address: string, topics: list<string>, data: string}
     */
    private function depositLog(string $assets, string $shares): array
    {
        return [
            'address' => self::VAULT,
            'topics' => [
                self::DEPOSIT_TOPIC0,
                '0x'.str_pad(strtolower(substr(self::SENDER, 2)), 64, '0', \STR_PAD_LEFT),
                '0x'.str_pad(strtolower(substr(self::OWNER, 2)), 64, '0', \STR_PAD_LEFT),
            ],
            'data' => '0x'.str_pad(dechex((int) $assets), 64, '0', \STR_PAD_LEFT).str_pad(dechex((int) $shares), 64, '0', \STR_PAD_LEFT),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function clientReturning(array $payload): MockHttpClient
    {
        return new MockHttpClient(static fn () => new MockResponse(json_encode($payload, \JSON_THROW_ON_ERROR)));
    }
}
