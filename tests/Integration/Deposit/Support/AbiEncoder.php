<?php

declare(strict_types=1);

namespace App\Tests\Integration\Deposit\Support;

use BcMath\Number;
use kornrunner\Keccak;

/**
 * Minimal calldata encoder for the 3 write calls the integration test needs to send as raw
 * `eth_sendTransaction` (impersonated account, no signature) — same manual word-encoding
 * approach as EthCallBlockchainReader, kept test-only rather than pushed into src/.
 */
final class AbiEncoder
{
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
