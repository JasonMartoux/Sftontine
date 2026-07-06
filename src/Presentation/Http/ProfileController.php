<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Deposit\Port\DepositTransactionRepositoryInterface;
use App\Application\Identity\Port\AuthenticatedUserInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ProfileController extends AbstractController
{
    public function __construct(
        private readonly DepositTransactionRepositoryInterface $depositTransactionRepository,
    ) {
    }

    #[Route('/profile', name: 'app_profile', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(): Response
    {
        $user = $this->getUser();

        if (!$user instanceof AuthenticatedUserInterface) {
            throw new \LogicException('Expected an authenticated user implementing AuthenticatedUserInterface.');
        }

        $domainUser = $user->getDomainUser();

        return $this->render('profile/index.html.twig', [
            'user' => $domainUser,
            'deposit_transaction' => $this->depositTransactionRepository->findLatestFor($domainUser->walletAddress),
        ]);
    }
}
