<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Deposit\Port\DepositTransactionRepositoryInterface;
use App\Application\Identity\Port\AuthenticatedUserInterface;
use App\Application\Tontine\Dto\GroupSummary;
use App\Application\Tontine\Port\TontineGroupRepositoryInterface;
use App\Application\Tontine\UseCase\CreateTontineGroup;
use App\Application\Tontine\UseCase\CreateTontineGroupCommand;
use App\Application\Tontine\UseCase\CreateTontineGroupError;
use App\Application\Tontine\UseCase\RecordContribution;
use App\Application\Tontine\UseCase\RecordContributionError;
use App\Domain\Deposit\TransactionHash;
use App\Domain\Identity\User;
use App\Domain\Tontine\MembershipRole;
use App\Domain\Tontine\TontineGroup;
use App\Presentation\Http\Dto\CreateTontineGroupInput;
use App\Presentation\Http\Dto\TontineGroupListItem;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class TontineGroupController extends AbstractController
{
    public function __construct(
        private readonly TontineGroupRepositoryInterface $groups,
        private readonly DepositTransactionRepositoryInterface $deposits,
        private readonly CreateTontineGroup $createTontineGroup,
        private readonly RecordContribution $recordContribution,
        private readonly ObjectMapperInterface $objectMapper,
        private readonly UriSigner $uriSigner,
    ) {
    }

    #[Route('/tontines', name: 'app_tontine_index', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->requireDomainUser();
        \assert(null !== $user->id);

        $items = array_map(
            function (TontineGroup $group) {
                \assert(null !== $group->id);
                $summary = new GroupSummary($group->id, $group->name, $group->contributionAmountDisplay(), $group->periodicity->value, $group->memberCount);
                $item = $this->objectMapper->map($summary, TontineGroupListItem::class);
                \assert($item instanceof TontineGroupListItem);

                return $item;
            },
            $this->groups->findAllForUserId($user->id),
        );

        return $this->render('tontine/index.html.twig', ['groups' => $items]);
    }

    #[Route('/tontines/nouvelle', name: 'app_tontine_new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->render('tontine/new.html.twig');
    }

    #[Route('/tontines', name: 'app_tontine_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('create_tontine', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $user = $this->requireDomainUser();
        $input = CreateTontineGroupInput::fromRequest($request);
        $command = $this->objectMapper->map($input, CreateTontineGroupCommand::class);
        \assert($command instanceof CreateTontineGroupCommand);

        $result = ($this->createTontineGroup)($user, $command);

        if (!$result->isSuccess) {
            return $this->render('tontine/new.html.twig', [
                'error' => self::createErrorMessage($result->error()),
                'name' => $input->name,
                'amount' => $input->amount,
                'periodicity' => $input->periodicity,
                'installments' => $input->installments,
            ], new Response(status: 422));
        }

        return $this->redirectToRoute('app_tontine_show', ['id' => $result->value()->id]);
    }

    #[Route('/tontines/{id}', name: 'app_tontine_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $user = $this->requireDomainUser();
        $group = $this->groups->find($id);
        if (null === $group) {
            throw $this->createNotFoundException('Tontine introuvable.');
        }

        $membership = $group->membershipFor($user);
        if (null === $membership) {
            throw $this->createAccessDeniedException("Vous n'êtes pas membre de cette tontine.");
        }

        $invitationUrl = null;
        if (MembershipRole::Admin === $membership->role) {
            $joinUrl = $this->generateUrl('app_tontine_join', ['id' => $id], UrlGeneratorInterface::ABSOLUTE_URL);
            $invitationUrl = $this->uriSigner->sign($joinUrl, new \DateInterval('P7D'));
        }

        return $this->render('tontine/show.html.twig', [
            'group' => $group,
            'invitationUrl' => $invitationUrl,
            'latestDeposit' => $this->deposits->findLatestFor($user->walletAddress),
            'isAdmin' => MembershipRole::Admin === $membership->role,
        ]);
    }

    #[Route('/tontines/{id}/cotisation', name: 'app_tontine_contribute', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function contribute(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('contribute_tontine_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $user = $this->requireDomainUser();

        try {
            $txHash = new TransactionHash((string) $request->request->get('txHash', ''));
        } catch (\InvalidArgumentException) {
            $this->addFlash('tontine_error', 'Hash de transaction invalide.');

            return $this->redirectToRoute('app_tontine_show', ['id' => $id]);
        }

        $result = ($this->recordContribution)($user, $id, $txHash);

        $this->addFlash(
            $result->isSuccess ? 'tontine_success' : 'tontine_error',
            $result->isSuccess ? 'Cotisation enregistrée.' : self::contributionErrorMessage($result->error()),
        );

        return $this->redirectToRoute('app_tontine_show', ['id' => $id]);
    }

    private function requireDomainUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof AuthenticatedUserInterface) {
            throw new \LogicException('Expected an authenticated user implementing AuthenticatedUserInterface.');
        }

        return $user->getDomainUser();
    }

    private static function createErrorMessage(CreateTontineGroupError $error): string
    {
        return match ($error) {
            CreateTontineGroupError::InvalidName => 'Le nom doit contenir entre 3 et 100 caractères.',
            CreateTontineGroupError::InvalidAmount => 'Le montant de cotisation doit être un nombre positif.',
            CreateTontineGroupError::InvalidPeriodicity => 'Périodicité invalide.',
            CreateTontineGroupError::InvalidCycleLength => 'Le nombre d\'échéances doit être compris entre 2 et 52.',
        };
    }

    private static function contributionErrorMessage(RecordContributionError $error): string
    {
        return match ($error) {
            RecordContributionError::GroupNotFound => 'Tontine introuvable.',
            RecordContributionError::NotAMember => "Vous n'êtes pas membre de cette tontine.",
            RecordContributionError::DepositNotFound => 'Aucun dépôt trouvé pour ce hash de transaction.',
            RecordContributionError::DepositNotConfirmed => "Ce dépôt n'est pas encore confirmé on-chain.",
            RecordContributionError::DepositWalletMismatch => "Ce dépôt provient d'un autre wallet que le vôtre.",
            RecordContributionError::AmountMismatch => 'Le montant du dépôt ne correspond pas à la cotisation du groupe.',
            RecordContributionError::AlreadyRecorded => 'Ce dépôt a déjà été enregistré comme cotisation.',
            RecordContributionError::CycleClosed => 'Le cycle en cours est clos.',
        };
    }
}
