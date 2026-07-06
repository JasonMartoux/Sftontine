<?php

declare(strict_types=1);

namespace App\Infrastructure\Privy;

use App\Application\Identity\Exception\PrivyTokenVerificationException;
use App\Application\Identity\Port\PrivyTokenVerifierInterface;
use App\Application\Identity\PrivyIdentity;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

final readonly class PrivyJwtVerifier implements PrivyTokenVerifierInterface
{
    private const ISSUER = 'privy.io';

    public function __construct(
        private string $privyAppId,
        private string $verificationKey,
    ) {
    }

    public function verify(string $jwt): PrivyIdentity
    {
        try {
            $claims = JWT::decode($jwt, new Key($this->verificationKey, 'ES256'));
        } catch (ExpiredException) {
            throw PrivyTokenVerificationException::expired();
        } catch (\Throwable $e) {
            throw PrivyTokenVerificationException::malformed($e->getMessage());
        }

        $issuer = $claims->iss ?? null;
        if (!\is_string($issuer) || self::ISSUER !== $issuer) {
            throw PrivyTokenVerificationException::invalidIssuer(\is_string($issuer) ? $issuer : '');
        }

        $audience = $claims->aud ?? null;
        if (!\is_string($audience) || $this->privyAppId !== $audience) {
            throw PrivyTokenVerificationException::invalidAudience(\is_string($audience) ? $audience : '');
        }

        $subject = $claims->sub ?? null;
        if (!\is_string($subject)) {
            throw PrivyTokenVerificationException::malformed('Missing "sub" claim.');
        }

        return new PrivyIdentity(subjectId: $subject);
    }
}
