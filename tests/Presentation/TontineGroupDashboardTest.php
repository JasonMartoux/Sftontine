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
use App\Domain\Tontine\Periodicity;
use App\Tests\Factory\Tontine\TontineGroupFactory;
use BcMath\Number;
use Doctrine\ORM\EntityManagerInterface;
use Firebase\JWT\JWT;
use Money\Currency;
use Money\Money;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class TontineGroupDashboardTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private const APP_ID = 'test-privy-app';

    public function testDashboardShowsPotTotalForAMember(): void
    {
        $wallet = '0x1111111111111111111111111111111111111111';
        $client = self::createClient();
        $this->login($client, 'did:privy:dashboard-member', $wallet);
        $creator = $this->findUser('did:privy:dashboard-member');
        $group = TontineGroupFactory::createOne([
            'creator' => $creator,
            'contributionAmount' => new Money('25000000', new Currency('USDC')),
        ]);

        $txHash = new TransactionHash('0x'.str_repeat('a1', 32));
        $this->seedConfirmedDeposit(new WalletAddress($wallet), $txHash, '25000000');

        $crawler = $client->request('GET', '/tontines/'.$group->id);
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $client->request('POST', '/tontines/'.$group->id.'/cotisation', ['_token' => $token, 'txHash' => (string) $txHash]);
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '$25.000000');
    }

    public function testDashboardHidesEcheancesAndStatutColumnsForPunctualGroup(): void
    {
        $wallet = '0x4444444444444444444444444444444444444444';
        $client = self::createClient();
        $this->login($client, 'did:privy:dashboard-punctual', $wallet);
        $creator = $this->findUser('did:privy:dashboard-punctual');
        $group = TontineGroupFactory::createOne([
            'creator' => $creator,
            'periodicity' => Periodicity::Punctual,
        ]);

        $client->request('GET', '/tontines/'.$group->id);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Ponctuelle');
        self::assertSelectorTextContains('body', 'Cotisations libres');
        self::assertSelectorTextNotContains('body', 'Échéances');
        self::assertSelectorTextNotContains('body', 'En retard');
        self::assertSelectorTextNotContains('body', 'À jour');
    }

    /**
     * The controller already blocks non-members from /tontines/{id} (see
     * TontineGroupControllerTest::testShowReturns403ForNonMember), but the component's
     * own re-render endpoint is a separate route — this proves the component re-checks
     * membership itself rather than trusting the LiveProp `groupId` it's given.
     */
    public function testComponentDeniesAccessForNonMemberEvenWithATamperedGroupId(): void
    {
        $client = self::createClient();
        $this->login($client, 'did:privy:dashboard-creator', '0x2222222222222222222222222222222222222222');
        $creator = $this->findUser('did:privy:dashboard-creator');
        $group = TontineGroupFactory::createOne(['creator' => $creator]);

        $this->login($client, 'did:privy:dashboard-outsider', '0x3333333333333333333333333333333333333333');

        $component = $this->createLiveComponent('TontineGroupDashboard', ['groupId' => $group->id], $client);
        $html = (string) $component->render();

        self::assertStringContainsString('Accès refusé', $html);
        self::assertStringNotContainsString('Pot commun', $html);
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
