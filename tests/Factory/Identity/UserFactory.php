<?php

declare(strict_types=1);

namespace App\Tests\Factory\Identity;

use App\Domain\Identity\User;
use App\Domain\Identity\WalletAddress;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<User>
 */
final class UserFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return User::class;
    }

    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#model-factories
     */
    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'privySubjectId' => 'did:privy:'.self::faker()->uuid(),
            'email' => self::faker()->email(),
            'walletAddress' => new WalletAddress('0x'.sha1(self::faker()->uuid())),
        ];
    }

    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#initialization
     */
    #[\Override]
    protected function initialize(): static
    {
        // User's constructor is private — only reachable via its named constructor.
        return $this->instantiateWith(Instantiator::namedConstructor('registerFromPrivy'));
    }
}
