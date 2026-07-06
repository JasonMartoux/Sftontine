<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateways;

use App\Application\Deposit\Port\TransactionReceiptReaderInterface;
use App\Application\Vault\Exception\BlockchainCallException;
use App\Domain\Deposit\DepositEvent;
use App\Domain\Deposit\TransactionHash;
use App\Domain\Deposit\TransactionReceipt;
use BcMath\Number;
use kornrunner\Keccak;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads a transaction receipt via a single JSON-RPC `eth_getTransactionReceipt` and, if the
 * vault emitted a `Deposit` event, decodes it manually (topics + data words) — same manual
 * ABI approach as EthCallBlockchainReader, no generic ABI/event-decoding library.
 */
final readonly class EthReceiptReader implements TransactionReceiptReaderInterface
{
    private const DEPOSIT_EVENT_SIGNATURE = 'Deposit(address,address,uint256,uint256)';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseRpcUrl,
        private string $vaultAddress,
    ) {
    }

    public function getReceipt(TransactionHash $txHash): ?TransactionReceipt
    {
        try {
            $response = $this->httpClient->request('POST', $this->baseRpcUrl, [
                'json' => [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'eth_getTransactionReceipt',
                    'params' => [(string) $txHash],
                ],
            ]);

            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface $e) {
            throw BlockchainCallException::rpcError($e->getMessage());
        }

        if (isset($payload['error'])) {
            $message = \is_array($payload['error']) ? ($payload['error']['message'] ?? 'unknown error') : 'unknown error';
            throw BlockchainCallException::rpcError(\is_string($message) ? $message : 'unknown error');
        }

        $result = $payload['result'] ?? null;

        if (null === $result) {
            return null; // not yet mined
        }

        if (!\is_array($result)) {
            throw BlockchainCallException::malformedResponse('Missing "result" field in eth_getTransactionReceipt response.');
        }

        $success = '0x1' === ($result['status'] ?? null);
        $logs = \is_array($result['logs'] ?? null) ? $result['logs'] : [];

        return new TransactionReceipt($success, $this->decodeDepositEvent($logs));
    }

    /**
     * @param array<mixed> $logs
     */
    private function decodeDepositEvent(array $logs): ?DepositEvent
    {
        $topic0 = '0x'.Keccak::hash(self::DEPOSIT_EVENT_SIGNATURE, 256);

        foreach ($logs as $log) {
            if (!\is_array($log)) {
                continue;
            }

            $address = $log['address'] ?? null;
            $topics = $log['topics'] ?? null;
            $data = $log['data'] ?? null;

            if (!\is_string($address) || !\is_array($topics) || !\is_string($data)) {
                continue;
            }

            $eventTopic = $topics[0] ?? null;
            $senderTopic = $topics[1] ?? null;
            $ownerTopic = $topics[2] ?? null;

            if (!\is_string($eventTopic) || !\is_string($senderTopic) || !\is_string($ownerTopic)) {
                continue;
            }

            if (strtolower($address) !== strtolower($this->vaultAddress) || strtolower($eventTopic) !== $topic0) {
                continue;
            }

            $words = str_split(substr($data, 2), 64);

            return new DepositEvent(
                sender: '0x'.substr($senderTopic, 26),
                owner: '0x'.substr($ownerTopic, 26),
                assets: new Number(self::hexToDecimal($words[0] ?? '0')),
                shares: new Number(self::hexToDecimal($words[1] ?? '0')),
            );
        }

        return null;
    }

    /**
     * @return numeric-string
     */
    private static function hexToDecimal(string $hex): string
    {
        $decimal = new Number('0');
        $sixteen = new Number('16');
        foreach (str_split($hex) as $char) {
            $decimal = $decimal->mul($sixteen)->add((string) hexdec($char));
        }

        return (string) $decimal;
    }
}
