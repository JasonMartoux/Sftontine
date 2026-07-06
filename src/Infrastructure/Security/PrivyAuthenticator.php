<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Application\Identity\Exception\PrivyTokenVerificationException;
use App\Application\Identity\Port\PrivyIdentityTokenVerifierInterface;
use App\Application\Identity\Port\PrivyTokenVerifierInterface;
use App\Application\Identity\Port\UserRepositoryInterface;
use App\Domain\Identity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class PrivyAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly PrivyTokenVerifierInterface $tokenVerifier,
        private readonly PrivyIdentityTokenVerifierInterface $identityTokenVerifier,
        private readonly UserRepositoryInterface $userRepository,
    ) {
    }

    public function supports(Request $request): bool
    {
        return 'app_auth_privy' === $request->attributes->get('_route');
    }

    public function authenticate(Request $request): Passport
    {
        $authorizationHeader = $request->headers->get('Authorization', '');
        if (!str_starts_with($authorizationHeader, 'Bearer ')) {
            throw new CustomUserMessageAuthenticationException('Missing bearer token.');
        }
        $accessToken = substr($authorizationHeader, 7);

        $identityToken = $request->headers->get('privy-id-token');
        if (null === $identityToken || '' === $identityToken) {
            throw new CustomUserMessageAuthenticationException('Missing Privy identity token.');
        }

        try {
            $identity = $this->tokenVerifier->verify($accessToken);
            $identityClaims = $this->identityTokenVerifier->verify($identityToken);
        } catch (PrivyTokenVerificationException $e) {
            throw new CustomUserMessageAuthenticationException($e->getMessage(), previous: $e);
        }

        if ($identity->subjectId !== $identityClaims->subjectId) {
            throw new CustomUserMessageAuthenticationException('Access token and identity token do not match the same user.');
        }

        return new SelfValidatingPassport(
            new UserBadge($identity->subjectId, function (string $subjectId) use ($identityClaims): SecurityUser {
                $user = $this->userRepository->findByPrivySubjectId($subjectId);

                if (null === $user) {
                    $user = User::registerFromPrivy($subjectId, $identityClaims->email, $identityClaims->walletAddress);
                } else {
                    $user->updateProfile($identityClaims->email, $identityClaims->walletAddress);
                }

                $this->userRepository->save($user);

                return new SecurityUser($user);
            }),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        return new JsonResponse(['redirectUrl' => '/profile']);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNAUTHORIZED);
    }
}
