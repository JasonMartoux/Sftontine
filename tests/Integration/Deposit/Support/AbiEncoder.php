<?php

declare(strict_types=1);

namespace App\Tests\Integration\Deposit\Support;

use BcMath\Number;
use Elliptic\EC;
use kornrunner\Keccak;

/**
 * Minimal calldata encoder for the 3 write calls the integration test needs to send as raw
 * `eth_sendTransaction` (impersonated account, no signature) — same manual word-encoding
 * approach as EthCallBlockchainReader, kept test-only rather than pushed into src/.
 */
final class AbiEncoder
{
    /**
     * @param list<array{type: string, value: mixed}> $params
     */
    public static function encodeDynamic(string $signature, array $params): string
    {
        $headWordCount = \count($params);
        $head = [];
        $tail = '';

        foreach ($params as $param) {
            if (self::isDynamicType($param['type'])) {
                $offset = $headWordCount * 32 + \strlen($tail) / 2; // $tail is hex chars, /2 for bytes
                $head[] = self::uintWord((string) $offset);
                $tail .= self::encodeDynamicTail($param['type'], $param['value']);
            } else {
                $head[] = self::encodeStaticWord($param['type'], $param['value']);
            }
        }

        return '0x'.self::selector($signature).implode('', $head).$tail;
    }

    /**
     * Signs a 32-byte digest with a raw secp256k1 private key, returning a 65-byte Safe/
     * Ethereum-style signature (r || s || v, v in {27,28}) — verified against
     * simplito/elliptic-php's actual EC::sign()/Signature API (r/s as BN, recoveryParam as
     * int) by independently reproducing an address recovery for Anvil's well-known account
     * #0 before this method was trusted (see Task 19's report for the standalone proof).
     */
    public static function signDigest(string $digestHex, string $privateKeyHex): string
    {
        $privateKeyHex = str_starts_with($privateKeyHex, '0x') ? substr($privateKeyHex, 2) : $privateKeyHex;
        $digestHex = str_starts_with($digestHex, '0x') ? substr($digestHex, 2) : $digestHex;

        $ec = new EC('secp256k1');
        $key = $ec->keyFromPrivate($privateKeyHex, 'hex');
        $signature = $ec->sign($digestHex, $key, 'hex', ['canonical' => true]);
        // elliptic-php ships no type declarations, so PHPStan sees `mixed` here — narrow it
        // explicitly (verified against the library's actual EC\Signature class, see docblock).
        \assert($signature instanceof EC\Signature);
        \assert($signature->r instanceof \BN\BN && $signature->s instanceof \BN\BN && \is_int($signature->recoveryParam));

        $r = $signature->r->toString(16, 64);
        $s = $signature->s->toString(16, 64);
        \assert(\is_string($r) && \is_string($s));
        $v = 27 + (1 & $signature->recoveryParam);

        return '0x'.$r.$s.dechex($v);
    }

    private static function isDynamicType(string $type): bool
    {
        return 'bytes' === $type || str_ends_with($type, '[]');
    }

    private static function encodeStaticWord(string $type, mixed $value): string
    {
        \assert(\is_string($value));

        return match ($type) {
            'address' => self::addressWord($value),
            'uint256', 'uint8' => self::uintWord($value),
            default => throw new \InvalidArgumentException(\sprintf('Unsupported static type: %s', $type)),
        };
    }

    private static function encodeDynamicTail(string $type, mixed $value): string
    {
        if ('bytes' === $type) {
            \assert(\is_string($value));
            $hex = str_starts_with($value, '0x') ? substr($value, 2) : $value;
            $lengthBytes = (int) (\strlen($hex) / 2);
            $paddedHexLength = (int) (ceil($lengthBytes / 32) * 32) * 2;

            return self::uintWord((string) $lengthBytes).str_pad($hex, $paddedHexLength, '0', \STR_PAD_RIGHT);
        }

        if ('address[]' === $type) {
            \assert(\is_array($value));
            $encoded = self::uintWord((string) \count($value));
            foreach ($value as $address) {
                \assert(\is_string($address));
                $encoded .= self::addressWord($address);
            }

            return $encoded;
        }

        throw new \InvalidArgumentException(\sprintf('Unsupported dynamic type: %s', $type));
    }

    public static function approve(string $spender, string $amount): string
    {
        return '0x'.self::selector('approve(address,uint256)').self::addressWord($spender).self::uintWord($amount);
    }

    public static function deposit(string $assets, string $receiver): string
    {
        return '0x'.self::selector('deposit(uint256,address)').self::uintWord($assets).self::addressWord($receiver);
    }

    /**
     * `connectPool(address,bytes)` with an empty `userData`: head = [pool, offset=0x40 (2
     * words), length=0], no data word needed since length is 0.
     */
    public static function connectPool(string $pool): string
    {
        return '0x'.self::selector('connectPool(address,bytes)').self::addressWord($pool).self::uintWord('64').self::uintWord('0');
    }

    public static function uintWord(string $decimal): string
    {
        \assert(is_numeric($decimal));
        $number = new Number($decimal);
        $sixteen = new Number('16');
        $hex = '';

        while ($number->compare(0) > 0) {
            $hex = dechex((int) (string) $number->mod($sixteen)).$hex;
            $number = $number->div($sixteen, 0);
        }

        return str_pad('' === $hex ? '0' : $hex, 64, '0', \STR_PAD_LEFT);
    }

    public static function addressWord(string $address): string
    {
        return str_pad(strtolower(substr($address, 2)), 64, '0', \STR_PAD_LEFT);
    }

    private static function selector(string $signature): string
    {
        return substr(Keccak::hash($signature, 256), 0, 8);
    }
}
