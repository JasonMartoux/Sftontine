<?php

declare(strict_types=1);

namespace App\Tests\Presentation;

use App\Application\Identity\Port\UserRepositoryInterface;
use App\Domain\Deposit\DepositEvent;
use App\Domain\Deposit\DepositTransaction;
use App\Domain\Deposit\TransactionHash;
use App\Domain\Deposit\TransactionReceipt;
use App\Domain\Identity\User;
use App\Domain\Identity\WalletAddress;
use App\Tests\Factory\Tontine\TontineGroupFactory;
use BcMath\Number;
use Doctrine\ORM\EntityManagerInterface;
use Firebase\JWT\JWT;
use Money\Currency;
use Money\Money;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TontineGroupControllerTest extends WebTestCase
{
    private const APP_ID = 'test-privy-app';

    public function testNewFormRendersForAuthenticatedUser(): void
    {
        $client = self::createClient();
        $this->login($client, 'did:privy:tontine-new-form', '0x1111111111111111111111111111111111111111');

        $client->request('GET', '/tontines/nouvelle');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/tontines"]');
    }

    public function testCreateWithValidDataRedirectsToShowPage(): void
    {
        $client = self::createClient();
        $this->login($client, 'did:privy:tontine-create-valid', '0x2222222222222222222222222222222222222222');

        $crawler = $client->request('GET', '/tontines/nouvelle');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/tontines', [
            '_token' => $token,
            'name' => 'Tontine des amis',
            'amount' => '25',
            'periodicity' => 'weekly',
            'installments' => '12',
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Tontine des amis');
    }

    public function testCreateWithInvalidDataShowsFrenchError(): void
    {
        $client = self::createClient();
        $this->login($client, 'did:privy:tontine-create-invalid', '0x3333333333333333333333333333333333333333');

        $crawler = $client->request('GET', '/tontines/nouvelle');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/tontines', [
            '_token' => $token,
            'name' => 'ab',
            'amount' => '25',
            'periodicity' => 'weekly',
            'installments' => '12',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', '3 et 100 caractères');
    }

    public function testShowReturns404ForUnknownGroup(): void
    {
        $client = self::createClient();
        $this->login($client, 'did:privy:tontine-show-404', '0x4444444444444444444444444444444444444444');

        $client->request('GET', '/tontines/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testShowReturns403ForNonMember(): void
    {
        $client = self::createClient();
        $this->login($client, 'did:privy:tontine-show-403-creator', '0x5555555555555555555555555555555555555555');
        $creator = $this->findUser('did:privy:tontine-show-403-creator');
        $group = TontineGroupFactory::createOne(['creator' => $creator]);

        // Re-authenticate the same client as a different, non-member user.
        $this->login($client, 'did:privy:tontine-show-403-outsider', '0x6666666666666666666666666666666666666666');
        $client->request('GET', '/tontines/'.$group->id);

        self::assertResponseStatusCodeSame(403);
    }

    public function testShowDisplaysInvitationLinkForAdmin(): void
    {
        $client = self::createClient();
        $this->login($client, 'did:privy:tontine-show-admin', '0x7777777777777777777777777777777777777777');
        $creator = $this->findUser('did:privy:tontine-show-admin');
        $group = TontineGroupFactory::createOne(['creator' => $creator]);

        $client->request('GET', '/tontines/'.$group->id);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[readonly]');
    }

    public function testContributeWithConfirmedDepositRecordsContribution(): void
    {
        $wallet = '0x8888888888888888888888888888888888888888';
        $client = self::createClient();
        $this->login($client, 'did:privy:tontine-contribute', $wallet);
        $creator = $this->findUser('did:privy:tontine-contribute');
        $group = TontineGroupFactory::createOne([
            'creator' => $creator,
            'contributionAmount' => new Money('25000000', new Currency('USDC')),
        ]);

        $txHash = '0x'.str_repeat('a1', 32);
        $this->seedConfirmedDeposit(new WalletAddress($wallet), new TransactionHash($txHash), '25000000');

        $crawler = $client->request('GET', '/tontines/'.$group->id);
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/tontines/'.$group->id.'/cotisation', [
            '_token' => $token,
            'txHash' => $txHash,
        ]);

        self::assertResponseRedirects('/tontines/'.$group->id);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Cotisation enregistrée');
    }

    public function testContributeWithInvalidTxHashShowsFrenchError(): void
    {
        $wallet = '0x9999999999999999999999999999999999999999';
        $client = self::createClient();
        $this->login($client, 'did:privy:tontine-contribute-bad-hash', $wallet);
        $creator = $this->findUser('did:privy:tontine-contribute-bad-hash');
        $group = TontineGroupFactory::createOne(['creator' => $creator]);

        $crawler = $client->request('GET', '/tontines/'.$group->id);
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/tontines/'.$group->id.'/cotisation', [
            '_token' => $token,
            'txHash' => 'not-a-hash',
        ]);

        self::assertResponseRedirects('/tontines/'.$group->id);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Hash de transaction invalide');
    }

    private function findUser(string $subject): User
    {
        $repository = self::getContainer()->get(UserRepositoryInterface::class);
        \assert($repository instanceof UserRepositoryInterface);

        $user = $repository->findByPrivySubjectId($subject);
        self::assertNotNull($user);

        return $user;
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
