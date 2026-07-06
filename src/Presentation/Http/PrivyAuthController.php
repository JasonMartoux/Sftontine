<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The actual authentication logic runs in App\Infrastructure\Security\PrivyAuthenticator,
 * which short-circuits the request (success or failure) before this controller is reached.
 * This route only exists so the firewall has something to match against.
 */
final class PrivyAuthController
{
    #[Route('/auth/privy', name: 'app_auth_privy', methods: ['POST'])]
    public function __invoke(): Response
    {
        return new JsonResponse(['error' => 'Authentication was not handled.'], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
