<?php

declare(strict_types=1);

namespace App\Presentation\Twig\Components;

use App\Application\Identity\Port\AuthenticatedUserInterface;
use App\Application\Vault\Port\YieldSnapshotRepositoryInterface;
use App\Domain\Vault\YieldSnapshot;
use BcMath\Number;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * "My position" dashboard: real numbers from the latest synced snapshot for an authenticated
 * member with a position, or a "not yet deposited" live simulation (à la supervault.suplabs.org)
 * driven by the protocol-wide APR otherwise.
 */
#[AsLiveComponent]
final class VaultPositionDashboard
{
    use DefaultActionTrait;

    private const int SECONDS_PER_YEAR = 365 * 24 * 60 * 60;
    private const int SECONDS_PER_DAY = 24 * 60 * 60;
    private const int DISPLAY_SCALE = 12;

    #[LiveProp(writable: true)]
    public string $previewAmount = '1000';

    private bool $snapshotResolved = false;
    private ?YieldSnapshot $snapshot = null;

    public function __construct(
        private readonly Security $security,
        private readonly YieldSnapshotRepositoryInterface $snapshotRepository,
    ) {
    }

    /**
     * Memoized: this component's getters are called repeatedly from Twig within the same render.
     */
    public function getSnapshot(): ?YieldSnapshot
    {
        if ($this->snapshotResolved) {
            return $this->snapshot;
        }

        $user = $this->security->getUser();
        $this->snapshot = $user instanceof AuthenticatedUserInterface
            ? $this->snapshotRepository->findLatestFor($user->getDomainUser()->walletAddress)
            : null;
        $this->snapshotResolved = true;

        return $this->snapshot;
    }

    public function hasPosition(): bool
    {
        $snapshot = $this->getSnapshot();

        return null !== $snapshot && $this->toNumber($snapshot->shares)->compare(0) > 0;
    }

    /**
     * Principal deposited (USDC, 6-dec minor units / 1e6).
     *
     * @return numeric-string
     */
    public function getPrincipalDisplay(): string
    {
        $snapshot = $this->getSnapshot();
        $raw = null !== $snapshot ? $this->toNumber($snapshot->principalMinorUnits) : new Number('0');

        return (string) $raw->div('1000000', 2);
    }

    public function isConnected(): bool
    {
        $snapshot = $this->getSnapshot();

        return null !== $snapshot && $snapshot->connected;
    }

    public function isPaused(): bool
    {
        $snapshot = $this->getSnapshot();

        return null !== $snapshot && $snapshot->paused;
    }

    public function getAprBasisPoints(): int
    {
        $snapshot = $this->getSnapshot() ?? $this->snapshotRepository->findMostRecent();

        return null !== $snapshot ? $snapshot->aprBasisPoints : 0;
    }

    /**
     * @return numeric-string
     */
    public function getAprPercent(): string
    {
        return (string) (new Number($this->getAprBasisPoints()))->div(100, 2);
    }

    /**
     * Yield received so far, in human USDC (18-dec USDCx raw amount / 1e18).
     *
     * @return numeric-string
     */
    public function getYieldReceivedDisplay(): string
    {
        $snapshot = $this->getSnapshot();
        $raw = null !== $snapshot ? $this->toNumber($snapshot->yieldReceived) : new Number('0');

        return (string) $raw->div('1000000000000000000', self::DISPLAY_SCALE);
    }

    /**
     * Per-second flow rate, in human USDC (18-dec USDCx raw rate / 1e18).
     *
     * @return numeric-string
     */
    public function getFlowRatePerSecondDisplay(): string
    {
        $snapshot = $this->getSnapshot();
        $raw = $this->hasPosition() && null !== $snapshot ? $this->toNumber($snapshot->flowRate) : new Number($this->getSimulatedFlowRatePerSecondRaw());

        return (string) $raw->div('1000000000000000000', self::DISPLAY_SCALE);
    }

    public function getCapturedAtTimestamp(): int
    {
        $snapshot = $this->getSnapshot();

        return null !== $snapshot ? $snapshot->capturedAt->getTimestamp() : time();
    }

    /**
     * @return numeric-string
     */
    public function getPerDayDisplay(): string
    {
        return (string) $this->toNumber($this->getFlowRatePerSecondDisplay())->mul((string) self::SECONDS_PER_DAY, 6);
    }

    /**
     * @return numeric-string
     */
    public function getPerYearDisplay(): string
    {
        return (string) $this->toNumber($this->getFlowRatePerSecondDisplay())->mul((string) self::SECONDS_PER_YEAR, 2);
    }

    /**
     * Simulated per-second earning (in 18-dec raw units, to share the same display scale as
     * the real flow rate) for "if you deposited $previewAmount" — pure client-facing math,
     * not an on-chain read: previewAmount × (aprBasisPoints / 10000) / secondsPerYear.
     *
     * @return numeric-string
     */
    private function getSimulatedFlowRatePerSecondRaw(): string
    {
        $amountWei = (new Number($this->normalizedPreviewAmount()))->mul('1000000000000000000');
        $annualYieldWei = $amountWei->mul((string) $this->getAprBasisPoints())->div('10000', 0);

        return (string) $annualYieldWei->div((string) self::SECONDS_PER_YEAR, 0);
    }

    /**
     * @return numeric-string
     */
    private function normalizedPreviewAmount(): string
    {
        return is_numeric($this->previewAmount) ? $this->previewAmount : '1000';
    }

    private function toNumber(string $raw): Number
    {
        \assert(is_numeric($raw));

        return new Number($raw);
    }
}
