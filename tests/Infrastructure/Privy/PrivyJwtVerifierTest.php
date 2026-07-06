<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Privy;

use App\Application\Identity\Exception\PrivyTokenVerificationException;
use App\Infrastructure\Privy\PrivyJwtVerifier;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;

final class PrivyJwtVerifierTest extends TestCase
{
    private const APP_ID = 'test-privy-app';

    private string $privateKey;
    private string $publicKey;
    private PrivyJwtVerifier $verifier;

    protected function setUp(): void
    {
        [$this->privateKey, $this->publicKey] = self::generateEs256KeyPair();
        $this->verifier = new PrivyJwtVerifier(self::APP_ID, $this->publicKey);
    }

    public function testValidTokenIsAccepted(): void
    {
        $token = $this->makeToken(['sub' => 'did:privy:abc123']);

        $identity = $this->verifier->verify($token);

        self::assertSame('did:privy:abc123', $identity->subjectId);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $token = $this->makeToken(['exp' => time() - 3600]);

        $this->expectException(PrivyTokenVerificationException::class);

        $this->verifier->verify($token);
    }

    public function testWrongAudienceIsRejected(): void
    {
        $token = $this->makeToken(['aud' => 'someone-elses-app']);

        $this->expectException(PrivyTokenVerificationException::class);

        $this->verifier->verify($token);
    }

    public function testWrongIssuerIsRejected(): void
    {
        $token = $this->makeToken(['iss' => 'evil.io']);

        $this->expectException(PrivyTokenVerificationException::class);

        $this->verifier->verify($token);
    }

    public function testTokenSignedWithWrongKeyIsRejected(): void
    {
        [$otherPrivateKey] = self::generateEs256KeyPair();
        $token = $this->makeToken(payload: [], privateKey: $otherPrivateKey);

        $this->expectException(PrivyTokenVerificationException::class);

        $this->verifier->verify($token);
    }

    public function testMalformedTokenIsRejected(): void
    {
        $this->expectException(PrivyTokenVerificationException::class);

        $this->verifier->verify('not-a-jwt');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function makeToken(array $payload = [], ?string $privateKey = null): string
    {
        $claims = array_merge([
            'iss' => 'privy.io',
            'aud' => self::APP_ID,
            'sub' => 'did:privy:default',
            'iat' => time(),
            'exp' => time() + 3600,
        ], $payload);

        return JWT::encode($claims, $privateKey ?? $this->privateKey, 'ES256');
    }

    /**
     * @return array{string, string} [privateKeyPem, publicKeyPem]
     */
    private static function generateEs256KeyPair(): array
    {
        $resource = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => \OPENSSL_KEYTYPE_EC,
        ]);

        if (false === $resource) {
            throw new \RuntimeException('Unable to generate an EC key pair.');
        }

        openssl_pkey_export($resource, $privateKey);
        if (!\is_string($privateKey)) {
            throw new \RuntimeException('Unable to export the EC private key.');
        }

        $details = openssl_pkey_get_details($resource);
        if (false === $details || !\is_string($details['key'])) {
            throw new \RuntimeException('Unable to read the EC key pair details.');
        }

        return [$privateKey, $details['key']];
    }
}
