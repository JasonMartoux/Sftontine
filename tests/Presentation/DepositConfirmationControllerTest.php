<?php

declare(strict_types=1);

namespace App\Tests\Presentation;

use Firebase\JWT\JWT;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Only exercises paths that return before reaching ConfirmDepositTransaction (auth guard,
 * payload validation): a real confirmation would call the wired EthReceiptReader, which
 * needs a live RPC node (see the Anvil fork integration tests instead). The use case itself
 * is covered by ConfirmDepositTransactionTest with mocked ports.
 */
final class DepositConfirmationControllerTest extends WebTestCase
{
    private const APP_ID = 'test-privy-app';

    public function testRequiresAuthentication(): void
    {
        $client = self::createClient();

        $client->request('POST', '/vault/deposit/confirm', content: json_encode(['txHash' => '0x'.str_repeat('a1', 32)], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(401);
    }

    public function testMissingTxHashIsRejected(): void
    {
        $client = self::createClient();
        $this->login($client, 'did:privy:deposit-confirm-missing-hash', '0x1111111111111111111111111111111111111111');

        $client->request('POST', '/vault/deposit/confirm', content: json_encode([], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(400);
    }

    public function testInvalidTxHashIsRejected(): void
    {
        $client = self::createClient();
        $this->login($client, 'did:privy:deposit-confirm-invalid-hash', '0x2222222222222222222222222222222222222222');

        $client->request('POST', '/vault/deposit/confirm', content: json_encode(['txHash' => 'not-a-hash'], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(400);
    }

    private function login(KernelBrowser $client, string $subject, string $walletAddress): void
    {
        $client->request(
            'POST',
            '/auth/privy',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->makeAccessToken($subject),
                'HTTP_PRIVY_ID_TOKEN' => $this->makeIdentityToken($subject, $walletAddress),
            ],
        );

        self::assertResponseIsSuccessful();
    }

    private function makeAccessToken(string $subject): string
    {
        return JWT::encode([
            'iss' => 'privy.io',
            'aud' => self::APP_ID,
            'sub' => $subject,
            'iat' => time(),
            'exp' => time() + 3600,
        ], $this->privateKey(), 'ES256');
    }

    private function makeIdentityToken(string $subject, string $walletAddress): string
    {
        return JWT::encode([
            'iss' => 'privy.io',
            'aud' => self::APP_ID,
            'sub' => $subject,
            'iat' => time(),
            'exp' => time() + 3600,
            'linked_accounts' => json_encode([
                ['type' => 'email', 'address' => $subject.'@example.com'],
                ['type' => 'wallet', 'address' => $walletAddress, 'chain_type' => 'ethereum'],
            ], \JSON_THROW_ON_ERROR),
        ], $this->privateKey(), 'ES256');
    }

    private function privateKey(): string
    {
        $privateKey = file_get_contents(__DIR__.'/../Fixtures/privy_test_private_key.pem');
        if (false === $privateKey) {
            throw new \RuntimeException('Missing test fixture: privy_test_private_key.pem.');
        }

        return $privateKey;
    }
}
