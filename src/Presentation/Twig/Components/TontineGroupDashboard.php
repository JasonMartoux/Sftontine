<?php

declare(strict_types=1);

namespace App\Presentation\Twig\Components;

use App\Application\Identity\Port\AuthenticatedUserInterface;
use App\Application\Tontine\Dto\GroupPotView;
use App\Application\Tontine\UseCase\GroupPotReader;
use App\Presentation\Http\Dto\TontineGroupListItem;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Group pot dashboard, polling like VaultPositionDashboard. Re-checks membership on every
 * render (not just on mount) so a tampered `groupId` LiveProp can never leak another
 * group's pot to a non-member.
 */
#[AsLiveComponent]
final class TontineGroupDashboard
{
    use DefaultActionTrait;

    #[LiveProp]
    public int $groupId;

    private bool $resolved = false;
    private ?GroupPotView $view = null;

    public function __construct(
        private readonly GroupPotReader $potReader,
        private readonly Security $security,
    ) {
    }

    public function getPot(): ?GroupPotView
    {
        if ($this->resolved) {
            return $this->view;
        }
        $this->resolved = true;

        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUserInterface) {
            return null;
        }

        $view = $this->potReader->read($this->groupId);
        if (null === $view) {
            return null;
        }

        $userId = $user->getDomainUser()->id;
        $isMember = false;
        foreach ($view->members as $member) {
            if (null !== $userId && $member->userId === $userId) {
                $isMember = true;
                break;
            }
        }

        $this->view = $isMember ? $view : null;

        return $this->view;
    }

    public function getPeriodicityLabel(): string
    {
        $pot = $this->getPot();

        return null === $pot ? '' : TontineGroupListItem::frenchLabel($pot->periodicity);
    }
}
