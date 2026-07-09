<?php

declare(strict_types=1);

namespace App\Domain\Vault;

use App\Domain\Identity\WalletAddress;
use BcMath\Number;
use Doctrine\ORM\Mapping as ORM;

/**
 * A persisted point-in-time capture of a member's vault position, written by the periodic
 * sync (#[AsPeriodicTask]) so the dashboard reads from the database instead of hitting the
 * Base RPC on every render/poll.
 */
#[ORM\Entity]
#[ORM\Table(name: 'yield_snapshot')]
#[ORM\Index(columns: ['wallet_address', 'captured_at'], name: 'idx_yield_snapshot_wallet_captured_at')]
final class YieldSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    public private(set) ?int $id = null;

    private function __construct(
        #[ORM\Column(name: 'wallet_address', type: 'wallet_address', length: 42)]
        public private(set) WalletAddress $walletAddress,
        #[ORM\Column(name: 'shares', type: 'string', length: 78)]
        public private(set) string $shares,
        #[ORM\Column(name: 'principal_minor_units', type: 'string', length: 78)]
        public private(set) string $principalMinorUnits,
        #[ORM\Column(name: 'yield_received', type: 'string', length: 78)]
        public private(set) string $yieldReceived,
        #[ORM\Column(name: 'flow_rate', type: 'string', length: 78)]
        public private(set) string $flowRate,
        #[ORM\Column(name: 'connected', type: 'boolean')]
        public private(set) bool $connected,
        #[ORM\Column(name: 'paused', type: 'boolean')]
        public private(set) bool $paused,
        #[ORM\Column(name: 'apr_basis_points', type: 'integer')]
        public private(set) int $aprBasisPoints,
        #[ORM\Column(name: 'captured_at', type: 'datetime_immutable')]
        public readonly \DateTimeImmutable $capturedAt,
    ) {
    }

    public static function fromPosition(WalletAddress $walletAddress, VaultPosition $position): self
    {
        return new self(
            walletAddress: $walletAddress,
            shares: (string) $position->shares,
            principalMinorUnits: $position->principal->getAmount(),
            yieldReceived: (string) $position->yieldReceived,
            flowRate: (string) $position->flowRate,
            connected: $position->connected,
            paused: $position->paused,
            aprBasisPoints: (int) (string) $position->aprBasisPoints,
            capturedAt: new \DateTimeImmutable(),
        );
    }

    public function principalDisplay(): string
    {
        \assert(is_numeric($this->principalMinorUnits));

        return (string) (new Number($this->principalMinorUnits))->div('1000000', 6);
    }

    public function yieldReceivedDisplay(): string
    {
        \assert(is_numeric($this->yieldReceived));

        return (string) (new Number($this->yieldReceived))->div('1000000000000000000', 6);
    }

    public function flowRatePerSecondDisplay(): string
    {
        \assert(is_numeric($this->flowRate));

        return (string) (new Number($this->flowRate))->div('1000000000000000000', 6);
    }
}
