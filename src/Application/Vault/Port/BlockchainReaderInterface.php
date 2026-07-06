<?php

declare(strict_types=1);

namespace App\Application\Vault\Port;

interface BlockchainReaderInterface
{
    /**
     * Calls a read-only (view) contract function via eth_call and returns its decoded outputs.
     *
     * @param list<string>                             $args        positional input arguments:
     *                                                              "0x..." for address, decimal string for uint256
     * @param list<'address'|'bool'|'int96'|'uint256'> $outputTypes expected ABI output types, in order
     *
     * @return list<bool|string> decoded outputs: "0x..." lowercase for address, decimal string for uint256/int96, bool for bool
     */
    public function call(string $contractAddress, string $functionSignature, array $args = [], array $outputTypes = ['uint256']): array;
}
