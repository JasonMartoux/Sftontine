<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Identity\Port\AuthenticatedUserInterface;
use App\Application\Tontine\Port\TontineGroupRepositoryInterface;
use App\Application\Tontine\UseCase\JoinTontineGroup;
use App\Application\Tontine\UseCase\JoinTontineGroupError;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\SignedUriException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Attribute\Route;

final class TontineInvitationController extends AbstractController
{
    public function __construct(
        private readonly TontineGroupRepositoryInterface $groups,
        private readonly JoinTontineGroup $joinTontineGroup,
        private readonly UriSigner $uriSigner,
    ) {
    }

    #[Route('/tontines/{id}/rejoindre', name: 'app_tontine_join', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function __invoke(int $id, Request $request): Response
    {
        try {
            $this->uriSigner->verify($request);
        } catch (SignedUriException) {
            return $this->render('tontine/join_error.html.twig', [], new Response(status: 403));
        }

        $group = $this->groups->find($id);
        if (null === $group) {
            throw $this->createNotFoundException('Tontine introuvable.');
        }

        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            if (!$user instanceof AuthenticatedUserInterface) {
                throw $this->createAccessDeniedException();
            }

            if (!$this->isCsrfTokenValid('join_tontine_'.$id, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $result = ($this->joinTontineGroup)($user->getDomainUser(), $id);

            if (!$result->isSuccess) {
                $this->addFlash('tontine_error', self::joinErrorMessage($result->error()));

                return $this->redirectToRoute('app_tontine_join', ['id' => $id, ...$request->query->all()]);
            }

            return $this->redirectToRoute('app_tontine_show', ['id' => $id]);
        }

        if (!$user instanceof AuthenticatedUserInterface) {
            $queryString = $request->getQueryString();
            $returnTo = $request->getPathInfo().(null !== $queryString && '' !== $queryString ? '?'.$queryString : '');

            return $this->render('tontine/join.html.twig', [
                'group' => $group,
                'authenticated' => false,
                'returnTo' => $returnTo,
            ]);
        }

        return $this->render('tontine/join.html.twig', [
            'group' => $group,
            'authenticated' => true,
            'currentUrl' => $request->getUri(),
            'alreadyMember' => null !== $group->membershipFor($user->getDomainUser()),
        ]);
    }

    private static function joinErrorMessage(JoinTontineGroupError $error): string
    {
        return match ($error) {
            JoinTontineGroupError::GroupNotFound => 'Tontine introuvable.',
            JoinTontineGroupError::GroupClosed => 'Cette tontine est fermée.',
            JoinTontineGroupError::AlreadyMember => 'Vous êtes déjà membre de cette tontine.',
        };
    }
}
