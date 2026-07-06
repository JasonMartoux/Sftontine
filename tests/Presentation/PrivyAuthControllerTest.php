<?php

declare(strict_types=1);

namespace App\Tests\Presentation;

use Firebase\JWT\JWT;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PrivyAuthControllerTest extends WebTestCase
{
    private const APP_ID = 'test-privy-app';
    private const WALLET_ADDRESS = '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed';
    private const SUBJECT = 'did:privy:functional-test-user';

    public function testValidTokenLogsInAndAllowsProfileAccess(): void
    {
        $client = self::createClient();

        $client->request(
            'POST',
            '/auth/privy',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->makeAccessToken(self::SUBJECT),
                'HTTP_PRIVY_ID_TOKEN' => $this->makeIdentityToken(self::SUBJECT),
            ],
        );

        self::assertResponseIsSuccessful();
        self::assertJson((string) $client->getResponse()->getContent());

        $client->request('GET', '/profile');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('a', self::WALLET_ADDRESS);
    }

    public function testInvalidTokenIsRejected(): void
    {
        $client = self::createClient();

        $client->request(
            'POST',
            '/auth/privy',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer not-a-valid-jwt',
                'HTTP_PRIVY_ID_TOKEN' => $this->makeIdentityToken(self::SUBJECT),
            ],
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testMissingIdentityTokenIsRejected(): void
    {
        $client = self::createClient();

        $client->request(
            'POST',
            '/auth/privy',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->makeAccessToken(self::SUBJECT)],
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testMismatchedSubjectBetweenTokensIsRejected(): void
    {
        $client = self::createClient();

        $client->request(
            'POST',
            '/auth/privy',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->makeAccessToken(self::SUBJECT),
                'HTTP_PRIVY_ID_TOKEN' => $this->makeIdentityToken('did:privy:someone-else'),
            ],
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testProfileRequiresAuthentication(): void
    {
        $client = self::createClient();

        $client->request('GET', '/profile');

        self::assertResponseStatusCodeSame(401);
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

    private function makeIdentityToken(string $subject): string
    {
        return JWT::encode([
            'iss' => 'privy.io',
            'aud' => self::APP_ID,
            'sub' => $subject,
            'iat' => time(),
            'exp' => time() + 3600,
            'linked_accounts' => json_encode([
                ['type' => 'email', 'address' => 'jason@example.com'],
                ['type' => 'wallet', 'address' => self::WALLET_ADDRESS, 'chain_type' => 'ethereum'],
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
