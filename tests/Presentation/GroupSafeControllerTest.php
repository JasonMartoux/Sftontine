<?php

declare(strict_types=1);

namespace App\Tests\Presentation;

use Firebase\JWT\JWT;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Only exercises paths that return before reaching the confirmation use cases (auth guard,
 * payload validation) — a real confirmation needs a live RPC node (see the Anvil fork
 * integration test instead). The use cases themselves are covered by
 * ConfirmGroupSafeDeploymentTest/ConfirmGroupSafeExecutionTest with mocked ports.
 */
final class GroupSafeControllerTest extends WebTestCase
{
    private const APP_ID = 'test-privy-app';

    public function testDeployConfirmRequiresAuthentication(): void
    {
        $client = self::createClient();

        $client->request('POST', '/tontines/1/safe/deploy/confirm', content: json_encode(['txHash' => '0x'.str_repeat('a1', 32)], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(401);
    }

    public function testDeployConfirmRejectsMissingTxHash(): void
    {
        $client = self::createClient();
        $this->login($client, 'did:privy:safe-deploy-missing-hash', '0x1111111111111111111111111111111111111111');

        $client->request('POST', '/tontines/1/safe/deploy/confirm', content: json_encode([], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(400);
    }

    public function testConnectPoolConfirmRequiresAuthentication(): void
    {
        $client = self::createClient();

        $client->request('POST', '/tontines/1/safe/connect-pool/confirm', content: json_encode(['txHash' => '0x'.str_repeat('a1', 32), 'safeTxHash' => '0x'.str_repeat('b2', 32)], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(401);
    }

    public function testConnectPoolConfirmRejectsMissingSafeTxHash(): void
    {
        $client = self::createClient();
        $this->login($client, 'did:privy:safe-connect-missing-hash', '0x2222222222222222222222222222222222222222');

        $client->request('POST', '/tontines/1/safe/connect-pool/confirm', content: json_encode(['txHash' => '0x'.str_repeat('a1', 32)], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(400);
    }

    public function testDeployConfirmForAnUnknownGroupReturns404(): void
    {
        $client = self::createClient();
        $this->login($client, 'did:privy:safe-deploy-unknown-group', '0x3333333333333333333333333333333333333333');

        $client->request('POST', '/tontines/999999/safe/deploy/confirm', content: json_encode(['txHash' => '0x'.str_repeat('a1', 32)], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(404);
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
