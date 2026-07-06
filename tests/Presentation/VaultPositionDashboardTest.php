<?php

declare(strict_types=1);

namespace App\Tests\Presentation;

use App\Domain\Identity\WalletAddress;
use App\Tests\Factory\Vault\YieldSnapshotFactory;
use Firebase\JWT\JWT;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VaultPositionDashboardTest extends WebTestCase
{
    private const APP_ID = 'test-privy-app';

    public function testAuthenticatedMemberWithoutPositionSeesSimulationPreview(): void
    {
        $client = self::createClient();
        $this->login($client, 'did:privy:dashboard-test-no-position', '0x1111111111111111111111111111111111111111');

        $client->request('GET', '/profile');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Live preview');
    }

    public function testMemberWithAPositionSeesRealNumbers(): void
    {
        $client = self::createClient();
        $walletAddress = '0x2222222222222222222222222222222222222222';
        $this->login($client, 'did:privy:dashboard-test-with-position', $walletAddress);

        YieldSnapshotFactory::createOne(['walletAddress' => new WalletAddress($walletAddress)]);

        $client->request('GET', '/profile');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Principal déposé');
        self::assertSelectorTextNotContains('body', 'Live preview');
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
