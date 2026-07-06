<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Privy;

use App\Application\Identity\Exception\PrivyTokenVerificationException;
use App\Domain\Identity\WalletAddress;
use App\Infrastructure\Privy\PrivyIdentityTokenVerifier;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;

final class PrivyIdentityTokenVerifierTest extends TestCase
{
    private const APP_ID = 'test-privy-app';
    private const WALLET_ADDRESS = '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed';

    private string $privateKey;
    private string $publicKey;
    private PrivyIdentityTokenVerifier $verifier;

    protected function setUp(): void
    {
        [$this->privateKey, $this->publicKey] = self::generateEs256KeyPair();
        $this->verifier = new PrivyIdentityTokenVerifier(self::APP_ID, $this->publicKey);
    }

    public function testValidTokenWithEmailAndWalletIsAccepted(): void
    {
        $token = $this->makeToken([
            'linked_accounts' => json_encode([
                ['type' => 'email', 'address' => 'jason@example.com'],
                ['type' => 'wallet', 'address' => self::WALLET_ADDRESS, 'chain_type' => 'ethereum'],
            ], \JSON_THROW_ON_ERROR),
        ]);

        $claims = $this->verifier->verify($token);

        self::assertSame('did:privy:default', $claims->subjectId);
        self::assertSame('jason@example.com', $claims->email);
        self::assertTrue($claims->walletAddress->equals(new WalletAddress(self::WALLET_ADDRESS)));
    }

    public function testWalletWithoutEmailIsAccepted(): void
    {
        $token = $this->makeToken([
            'linked_accounts' => json_encode([
                ['type' => 'wallet', 'address' => self::WALLET_ADDRESS, 'chain_type' => 'ethereum'],
            ], \JSON_THROW_ON_ERROR),
        ]);

        $claims = $this->verifier->verify($token);

        self::assertNull($claims->email);
    }

    public function testMissingWalletAccountIsRejected(): void
    {
        $token = $this->makeToken([
            'linked_accounts' => json_encode([
                ['type' => 'email', 'address' => 'jason@example.com'],
            ], \JSON_THROW_ON_ERROR),
        ]);

        $this->expectException(PrivyTokenVerificationException::class);

        $this->verifier->verify($token);
    }

    public function testNonEthereumWalletIsIgnored(): void
    {
        $token = $this->makeToken([
            'linked_accounts' => json_encode([
                ['type' => 'wallet', 'address' => 'SomeSolanaAddress', 'chain_type' => 'solana'],
            ], \JSON_THROW_ON_ERROR),
        ]);

        $this->expectException(PrivyTokenVerificationException::class);

        $this->verifier->verify($token);
    }

    public function testMissingLinkedAccountsClaimIsRejected(): void
    {
        $token = $this->makeToken([]);

        $this->expectException(PrivyTokenVerificationException::class);

        $this->verifier->verify($token);
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

    public function testMalformedTokenIsRejected(): void
    {
        $this->expectException(PrivyTokenVerificationException::class);

        $this->verifier->verify('not-a-jwt');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function makeToken(array $payload = []): string
    {
        $claims = array_merge([
            'iss' => 'privy.io',
            'aud' => self::APP_ID,
            'sub' => 'did:privy:default',
            'iat' => time(),
            'exp' => time() + 3600,
        ], $payload);

        return JWT::encode($claims, $this->privateKey, 'ES256');
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
