<?php

declare(strict_types=1);

namespace App\Tests\Presentation;

use App\Application\Identity\Port\UserRepositoryInterface;
use App\Domain\Identity\User;
use App\Tests\Factory\Tontine\TontineGroupFactory;
use Firebase\JWT\JWT;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class TontineInvitationControllerTest extends WebTestCase
{
    private const APP_ID = 'test-privy-app';

    public function testUnsignedUrlIsRejected(): void
    {
        $client = self::createClient();
        $this->login($client, 'did:privy:invite-unsigned', '0x1111111111111111111111111111111111111111');
        $creator = $this->findUser('did:privy:invite-unsigned');
        $groupId = $this->groupId(['creator' => $creator]);

        $client->request('GET', '/tontines/'.$groupId.'/rejoindre');

        self::assertResponseStatusCodeSame(403);
    }

    public function testExpiredUrlIsRejected(): void
    {
        $client = self::createClient();
        $this->login($client, 'did:privy:invite-expired', '0x2222222222222222222222222222222222222222');
        $creator = $this->findUser('did:privy:invite-expired');
        $groupId = $this->groupId(['creator' => $creator]);

        $signedUrl = $this->sign($groupId, new \DateTimeImmutable('-1 hour'));
        $client->request('GET', $signedUrl);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousVisitorSeesLoginPrompt(): void
    {
        $client = self::createClient();
        $this->login($client, 'did:privy:invite-anon-creator', '0x3333333333333333333333333333333333333333');
        $creator = $this->findUser('did:privy:invite-anon-creator');
        $groupId = $this->groupId(['creator' => $creator]);
        $signedUrl = $this->sign($groupId);

        // Drop the session cookie on the same client to simulate an anonymous visitor.
        $client->getCookieJar()->clear();
        $client->request('GET', $signedUrl);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Connectez-vous');
    }

    public function testAuthenticatedUserCanJoinViaSignedLink(): void
    {
        $client = self::createClient();
        $this->login($client, 'did:privy:invite-join-creator', '0x4444444444444444444444444444444444444444');
        $creator = $this->findUser('did:privy:invite-join-creator');
        $groupId = $this->groupId(['creator' => $creator]);
        $signedUrl = $this->sign($groupId);

        $this->login($client, 'did:privy:invite-join-member', '0x5555555555555555555555555555555555555555');
        $crawler = $client->request('GET', $signedUrl);

        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $formAction = (string) $crawler->filter('form')->attr('action');

        $client->request('POST', $formAction, ['_token' => $token]);

        self::assertResponseRedirects('/tontines/'.$groupId);
    }

    public function testAlreadyMemberSeesConfirmationInsteadOfForm(): void
    {
        $client = self::createClient();
        $this->login($client, 'did:privy:invite-already-member', '0x6666666666666666666666666666666666666666');
        $creator = $this->findUser('did:privy:invite-already-member');
        $groupId = $this->groupId(['creator' => $creator]);
        $signedUrl = $this->sign($groupId);

        $client->request('GET', $signedUrl);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'déjà membre');
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function groupId(array $attributes): int
    {
        $groupId = TontineGroupFactory::createOne($attributes)->id;
        self::assertNotNull($groupId);

        return $groupId;
    }

    private function sign(int $groupId, ?\DateTimeImmutable $expiration = null): string
    {
        $urlGenerator = self::getContainer()->get(UrlGeneratorInterface::class);
        \assert($urlGenerator instanceof UrlGeneratorInterface);
        $uriSigner = self::getContainer()->get(UriSigner::class);
        \assert($uriSigner instanceof UriSigner);

        $url = $urlGenerator->generate('app_tontine_join', ['id' => $groupId], UrlGeneratorInterface::ABSOLUTE_URL);

        return $uriSigner->sign($url, $expiration ?? new \DateTimeImmutable('+7 days'));
    }

    private function findUser(string $subject): User
    {
        $repository = self::getContainer()->get(UserRepositoryInterface::class);
        \assert($repository instanceof UserRepositoryInterface);

        $user = $repository->findByPrivySubjectId($subject);
        self::assertNotNull($user);

        return $user;
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
