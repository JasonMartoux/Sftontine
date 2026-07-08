<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Identity\Port\AuthenticatedUserInterface;
use App\Application\Safe\UseCase\ConfirmGroupSafeDeployment;
use App\Application\Safe\UseCase\ConfirmGroupSafeExecution;
use App\Application\Safe\UseCase\GroupSafeError;
use App\Domain\Deposit\TransactionHash;
use App\Domain\Identity\User;
use App\Domain\Safe\SafeTransactionPurpose;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class GroupSafeController extends AbstractController
{
    public function __construct(
        private readonly ConfirmGroupSafeDeployment $confirmDeployment,
        private readonly ConfirmGroupSafeExecution $confirmExecution,
    ) {
    }

    #[Route('/tontines/{id}/safe/deploy/confirm', name: 'app_tontine_safe_deploy_confirm', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function confirmDeploy(int $id, Request $request): JsonResponse
    {
        $user = $this->requireDomainUser();

        $txHash = self::parseTxHash($request, 'txHash');
        if (null === $txHash) {
            return $this->json(['error' => 'Missing or invalid "txHash".'], Response::HTTP_BAD_REQUEST);
        }

        $result = ($this->confirmDeployment)($user, $id, $txHash);

        if (!$result->isSuccess) {
            return $this->json(['error' => self::errorMessage($result->error())], self::statusFor($result->error()));
        }

        return $this->json(['status' => $result->value()->status, 'safeAddress' => $result->value()->safeAddress]);
    }

    #[Route('/tontines/{id}/safe/connect-pool/confirm', name: 'app_tontine_safe_connect_pool_confirm', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function confirmConnectPool(int $id, Request $request): JsonResponse
    {
        $user = $this->requireDomainUser();

        $txHash = self::parseTxHash($request, 'txHash');
        $safeTxHash = self::parseTxHash($request, 'safeTxHash');
        if (null === $txHash || null === $safeTxHash) {
            return $this->json(['error' => 'Missing or invalid "txHash"/"safeTxHash".'], Response::HTTP_BAD_REQUEST);
        }

        $result = ($this->confirmExecution)($user, $id, SafeTransactionPurpose::ConnectYieldPool, $txHash, $safeTxHash);

        if (!$result->isSuccess) {
            return $this->json(['error' => self::errorMessage($result->error())], self::statusFor($result->error()));
        }

        return $this->json(['status' => $result->value()->status]);
    }

    private function requireDomainUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof AuthenticatedUserInterface) {
            throw new \LogicException('Expected an authenticated user implementing AuthenticatedUserInterface.');
        }

        return $user->getDomainUser();
    }

    private static function parseTxHash(Request $request, string $field): ?TransactionHash
    {
        $payload = json_decode($request->getContent(), associative: true);
        $value = \is_array($payload) ? ($payload[$field] ?? null) : null;

        if (!\is_string($value)) {
            return null;
        }

        try {
            return new TransactionHash($value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private static function statusFor(GroupSafeError $error): int
    {
        return match ($error) {
            GroupSafeError::GroupNotFound => Response::HTTP_NOT_FOUND,
            GroupSafeError::NotAnAdmin => Response::HTTP_FORBIDDEN,
        };
    }

    private static function errorMessage(GroupSafeError $error): string
    {
        return match ($error) {
            GroupSafeError::GroupNotFound => 'Tontine introuvable.',
            GroupSafeError::NotAnAdmin => "Seul l'administrateur du groupe peut effectuer cette action.",
        };
    }
}
