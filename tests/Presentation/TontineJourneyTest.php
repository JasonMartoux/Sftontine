<?php

declare(strict_types=1);

namespace App\Tests\Presentation;

use App\Application\Tontine\Port\TontineGroupRepositoryInterface;
use App\Domain\Deposit\DepositEvent;
use App\Domain\Deposit\DepositTransaction;
use App\Domain\Deposit\TransactionHash;
use App\Domain\Deposit\TransactionReceipt;
use App\Domain\Identity\WalletAddress;
use BcMath\Number;
use Doctrine\ORM\EntityManagerInterface;
use Firebase\JWT\JWT;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end DoD for JAS-25: A creates a group, generates a signed invite, B logs in and
 * joins through it, B's confirmed on-chain deposit is recorded as a contribution, and the
 * group page reflects both members and the combined pot.
 */
final class TontineJourneyTest extends WebTestCase
{
    private const APP_ID = 'test-privy-app';
    private const WALLET_A = '0x1111111111111111111111111111111111111111';
    private const WALLET_B = '0x2222222222222222222222222222222222222222';

    public function testCreateInviteJoinAndContributeJourney(): void
    {
        $client = self::createClient();

        // A creates the group.
        $this->login($client, 'did:privy:journey-a', self::WALLET_A);
        $crawler = $client->request('GET', '/tontines/nouvelle');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $client->request('POST', '/tontines', [
            '_token' => $token,
            'name' => 'Tontine du voyage',
            'amount' => '25',
            'periodicity' => 'weekly',
            'installments' => '12',
        ]);
        self::assertResponseRedirects();
        $showUrl = (string) $client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#^/tontines/\d+$#', $showUrl);
        $groupId = (int) substr($showUrl, \strlen('/tontines/'));

        // Provision a Safe for the group so the contribution form can be rendered.
        $this->provisionSafe($groupId);

        // A generates the invitation link from the group page.
        $crawler = $client->request('GET', $showUrl);
        self::assertResponseIsSuccessful();
        $invitationUrl = (string) $crawler->filter('input[readonly]')->attr('value');
        self::assertNotSame('', $invitationUrl);

        // B logs in and follows the invitation link.
        $this->login($client, 'did:privy:journey-b', self::WALLET_B);
        $crawler = $client->request('GET', $invitationUrl);
        self::assertResponseIsSuccessful();
        $joinToken = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $joinFormAction = (string) $crawler->filter('form')->attr('action');

        $client->request('POST', $joinFormAction, ['_token' => $joinToken]);
        self::assertResponseRedirects('/tontines/'.$groupId);

        // B deposits on-chain (simulated: a confirmed DepositTransaction) and records the contribution.
        $txHash = new TransactionHash('0x'.str_repeat('b2', 32));
        $this->seedConfirmedDeposit(new WalletAddress(self::WALLET_B), $txHash, '25000000');

        $crawler = $client->request('GET', '/tontines/'.$groupId);
        $contributeToken = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $client->request('POST', '/tontines/'.$groupId.'/cotisation', [
            '_token' => $contributeToken,
            'txHash' => (string) $txHash,
        ]);
        self::assertResponseRedirects('/tontines/'.$groupId);

        // The group page now shows both members and the combined pot.
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '$25.000000');
        self::assertSelectorTextContains('body', 'Cotisation enregistrée');
    }

    private function provisionSafe(int $groupId): void
    {
        $groups = self::getContainer()->get(TontineGroupRepositoryInterface::class);
        \assert($groups instanceof TontineGroupRepositoryInterface);

        $group = $groups->find($groupId);
        self::assertNotNull($group);

        $group->provisionSafe(new WalletAddress('0x4444444444444444444444444444444444444444'));
        $groups->save($group);
    }

    /**
     * @param numeric-string $amountMinorUnits
     */
    private function seedConfirmedDeposit(WalletAddress $walletAddress, TransactionHash $txHash, string $amountMinorUnits): void
    {
        $deposit = DepositTransaction::pending($walletAddress, $txHash);
        $deposit->applyReceipt(new TransactionReceipt(
            success: true,
            depositEvent: new DepositEvent($walletAddress->value, $walletAddress->value, new Number($amountMinorUnits), new Number($amountMinorUnits)),
        ));

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->persist($deposit);
        $entityManager->flush();
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
