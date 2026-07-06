<?php

declare(strict_types=1);

namespace App\Application\Vault\Exception;

final class BlockchainCallException extends \RuntimeException
{
    public static function rpcError(string $message): self
    {
        return new self(\sprintf('Base RPC error: %s', $message));
    }

    public static function malformedResponse(string $reason): self
    {
        return new self(\sprintf('Malformed eth_call response: %s', $reason));
    }
}
