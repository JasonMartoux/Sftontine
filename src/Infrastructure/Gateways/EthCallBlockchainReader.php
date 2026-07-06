<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateways;

use App\Application\Vault\Exception\BlockchainCallException;
use App\Application\Vault\Port\BlockchainReaderInterface;
use BcMath\Number;
use kornrunner\Keccak;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads on-chain view functions via a single JSON-RPC `eth_call`, with manual ABI encoding
 * (selectors via keccak, arguments/outputs as 32-byte words) instead of a full web3 client —
 * the only inputs this app ever needs to send are `address` and `uint256`.
 */
final readonly class EthCallBlockchainReader implements BlockchainReaderInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseRpcUrl,
    ) {
    }

    public function call(string $contractAddress, string $functionSignature, array $args = [], array $outputTypes = ['uint256']): array
    {
        $data = '0x'.self::selector($functionSignature).self::encodeArgs($args);

        try {
            $response = $this->httpClient->request('POST', $this->baseRpcUrl, [
                'json' => [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'eth_call',
                    'params' => [
                        ['to' => $contractAddress, 'data' => $data],
                        'latest',
                    ],
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
        if (!\is_string($result)) {
            throw BlockchainCallException::malformedResponse('Missing "result" field in eth_call response.');
        }

        return self::decodeOutputs($result, $outputTypes);
    }

    private static function selector(string $signature): string
    {
        return substr(Keccak::hash($signature, 256), 0, 8);
    }

    /**
     * @param list<string> $args
     */
    private static function encodeArgs(array $args): string
    {
        $encoded = '';
        foreach ($args as $arg) {
            $encoded .= self::encodeWord($arg);
        }

        return $encoded;
    }

    private static function encodeWord(string $arg): string
    {
        if (str_starts_with($arg, '0x')) {
            return str_pad(strtolower(substr($arg, 2)), 64, '0', \STR_PAD_LEFT);
        }

        return str_pad(self::decimalToHex($arg), 64, '0', \STR_PAD_LEFT);
    }

    /**
     * @param list<'address'|'bool'|'int96'|'uint256'> $outputTypes
     *
     * @return list<bool|string>
     */
    private static function decodeOutputs(string $result, array $outputTypes): array
    {
        $hex = substr($result, 2);
        $words = str_split($hex, 64);

        $decoded = [];
        foreach ($outputTypes as $i => $type) {
            $word = $words[$i] ?? str_repeat('0', 64);
            $decoded[] = match ($type) {
                'address' => '0x'.substr($word, 24),
                'bool' => str_ends_with($word, '1'),
                'uint256' => self::hexToDecimal($word),
                'int96' => self::decodeSigned($word),
            };
        }

        return $decoded;
    }

    /**
     * Two's complement over the full 256-bit word: the EVM sign-extends signed values
     * (e.g. int96) to fill the whole word, so decoding the word as a signed 256-bit
     * integer already yields the correct value.
     */
    private static function decodeSigned(string $word): string
    {
        $unsigned = new Number(self::hexToDecimal($word));

        // Bit 255 is the top bit of the first hex nibble (0x8 mask).
        if ((hexdec($word[0]) & 0x8) !== 0) {
            $modulus = (new Number('2'))->pow(256);

            return (string) $unsigned->sub($modulus);
        }

        return (string) $unsigned;
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

    private static function decimalToHex(string $decimal): string
    {
        \assert(is_numeric($decimal));
        $number = new Number($decimal);
        $sixteen = new Number('16');
        $hex = '';

        while ($number->compare(0) > 0) {
            $hex = dechex((int) (string) $number->mod($sixteen)).$hex;
            $number = $number->div($sixteen, 0);
        }

        return '' === $hex ? '0' : $hex;
    }
}
