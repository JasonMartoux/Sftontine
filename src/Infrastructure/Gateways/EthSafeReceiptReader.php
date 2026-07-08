<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateways;

use App\Application\Safe\Dto\SafeDeploymentReceipt;
use App\Application\Safe\Dto\SafeExecutionReceipt;
use App\Application\Safe\Port\SafeReceiptReaderInterface;
use App\Application\Vault\Exception\BlockchainCallException;
use App\Domain\Deposit\TransactionHash;
use kornrunner\Keccak;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads a Safe deployment or execTransaction receipt via a single JSON-RPC
 * `eth_getTransactionReceipt`, manually decoding the ProxyCreation/ExecutionSuccess/
 * ExecutionFailure events — same manual ABI approach as EthReceiptReader.
 */
final readonly class EthSafeReceiptReader implements SafeReceiptReaderInterface
{
    private const PROXY_CREATION_SIGNATURE = 'ProxyCreation(address,address)';
    private const EXECUTION_SUCCESS_SIGNATURE = 'ExecutionSuccess(bytes32,uint256)';
    private const EXECUTION_FAILURE_SIGNATURE = 'ExecutionFailure(bytes32,uint256)';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseRpcUrl,
        private string $proxyFactoryAddress,
    ) {
    }

    public function getDeploymentReceipt(TransactionHash $txHash): ?SafeDeploymentReceipt
    {
        $result = $this->fetchReceipt($txHash);
        if (null === $result) {
            return null;
        }

        [$success, $logs] = $result;
        if (!$success) {
            return new SafeDeploymentReceipt(false, null);
        }

        $topic0 = '0x'.Keccak::hash(self::PROXY_CREATION_SIGNATURE, 256);

        foreach ($logs as $log) {
            if (!\is_array($log)) {
                continue;
            }

            if (!$this->isFromAddress($log, $this->proxyFactoryAddress) || !$this->hasTopic0($log, $topic0)) {
                continue;
            }

            // `proxy` is indexed (topics[1]) — ProxyCreation(address indexed proxy, address singleton).
            $topics = $log['topics'] ?? null;
            $proxyTopic = \is_array($topics) ? ($topics[1] ?? null) : null;
            if (!\is_string($proxyTopic)) {
                continue;
            }

            return new SafeDeploymentReceipt(true, '0x'.substr($proxyTopic, 26));
        }

        return new SafeDeploymentReceipt(false, null);
    }

    public function getExecutionReceipt(TransactionHash $txHash, TransactionHash $safeTxHash): ?SafeExecutionReceipt
    {
        $result = $this->fetchReceipt($txHash);
        if (null === $result) {
            return null;
        }

        [, $logs] = $result;

        $successTopic0 = '0x'.Keccak::hash(self::EXECUTION_SUCCESS_SIGNATURE, 256);
        $failureTopic0 = '0x'.Keccak::hash(self::EXECUTION_FAILURE_SIGNATURE, 256);
        $expected = strtolower($safeTxHash->value);

        foreach ($logs as $log) {
            if (!\is_array($log)) {
                continue;
            }

            // `txHash` is indexed (topics[1]) — ExecutionSuccess/Failure(bytes32 indexed
            // txHash, uint256 payment). It's already a full bytes32, no slicing needed.
            $topics = $log['topics'] ?? null;
            $loggedTxHash = \is_array($topics) && \is_string($topics[1] ?? null) ? strtolower($topics[1]) : null;

            if ($loggedTxHash !== $expected) {
                continue;
            }

            if ($this->hasTopic0($log, $successTopic0)) {
                return new SafeExecutionReceipt(true);
            }

            if ($this->hasTopic0($log, $failureTopic0)) {
                return new SafeExecutionReceipt(false);
            }
        }

        return null;
    }

    /**
     * @return array{0: bool, 1: list<mixed>}|null
     */
    private function fetchReceipt(TransactionHash $txHash): ?array
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
            return null;
        }

        if (!\is_array($result)) {
            throw BlockchainCallException::malformedResponse('Missing "result" field in eth_getTransactionReceipt response.');
        }

        $success = '0x1' === ($result['status'] ?? null);
        $logs = \is_array($result['logs'] ?? null) ? array_values($result['logs']) : [];

        return [$success, $logs];
    }

    /**
     * @param array<int|string, mixed> $log
     */
    private function isFromAddress(array $log, string $address): bool
    {
        $logAddress = $log['address'] ?? null;

        return \is_string($logAddress) && strtolower($logAddress) === strtolower($address);
    }

    /**
     * @param array<int|string, mixed> $log
     */
    private function hasTopic0(array $log, string $topic0): bool
    {
        $topics = $log['topics'] ?? null;
        if (!\is_array($topics)) {
            return false;
        }

        $eventTopic = $topics[0] ?? null;

        return \is_string($eventTopic) && strtolower($eventTopic) === strtolower($topic0);
    }
}
