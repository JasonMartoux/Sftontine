<?php

declare(strict_types=1);

namespace App\Application\Identity\Exception;

final class PrivyTokenVerificationException extends \RuntimeException
{
    public static function expired(): self
    {
        return new self('Privy token has expired.');
    }

    public static function invalidIssuer(string $issuer): self
    {
        return new self(\sprintf('Unexpected token issuer "%s".', $issuer));
    }

    public static function invalidAudience(string $audience): self
    {
        return new self(\sprintf('Unexpected token audience "%s".', $audience));
    }

    public static function malformed(string $reason): self
    {
        return new self(\sprintf('Malformed Privy token: %s', $reason));
    }

    public static function missingWalletAddress(): self
    {
        return new self('Identity token has no linked Ethereum wallet account.');
    }
}
