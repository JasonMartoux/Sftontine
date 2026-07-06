<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
final class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    public private(set) ?int $id = null;

    private function __construct(
        #[ORM\Column(name: 'privy_subject_id', type: 'string', length: 255, unique: true)]
        public private(set) string $privySubjectId,
        #[ORM\Column(name: 'email', type: 'string', length: 255, nullable: true)]
        public private(set) ?string $email,
        #[ORM\Column(name: 'wallet_address', type: 'wallet_address', length: 42, unique: true)]
        public private(set) WalletAddress $walletAddress,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        public readonly \DateTimeImmutable $createdAt,
    ) {
    }

    public static function registerFromPrivy(string $privySubjectId, ?string $email, WalletAddress $walletAddress): self
    {
        return new self($privySubjectId, $email, $walletAddress, new \DateTimeImmutable());
    }

    public function updateProfile(?string $email, WalletAddress $walletAddress): void
    {
        $this->email = $email;
        $this->walletAddress = $walletAddress;
    }
}
