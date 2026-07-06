<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Deposit\UseCase\ConfirmDepositTransaction;
use App\Application\Identity\Port\AuthenticatedUserInterface;
use App\Domain\Deposit\TransactionHash;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class DepositConfirmationController extends AbstractController
{
    public function __construct(
        private readonly ConfirmDepositTransaction $confirmDepositTransaction,
    ) {
    }

    #[Route('/vault/deposit/confirm', name: 'app_vault_deposit_confirm', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof AuthenticatedUserInterface) {
            throw new \LogicException('Expected an authenticated user implementing AuthenticatedUserInterface.');
        }

        $payload = json_decode($request->getContent(), associative: true);
        $txHash = \is_array($payload) ? ($payload['txHash'] ?? null) : null;

        if (!\is_string($txHash)) {
            return $this->json(['error' => 'Missing "txHash".'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $transactionHash = new TransactionHash($txHash);
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Invalid "txHash".'], Response::HTTP_BAD_REQUEST);
        }

        $depositTransaction = ($this->confirmDepositTransaction)(
            $user->getDomainUser()->walletAddress,
            $transactionHash,
        );

        return $this->json(['status' => $depositTransaction->status->value]);
    }
}
