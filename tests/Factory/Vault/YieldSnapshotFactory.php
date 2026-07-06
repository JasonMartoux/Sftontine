<?php

declare(strict_types=1);

namespace App\Tests\Factory\Vault;

use App\Domain\Identity\WalletAddress;
use App\Domain\Vault\VaultPosition;
use App\Domain\Vault\YieldSnapshot;
use BcMath\Number;
use Money\Currency;
use Money\Money;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<YieldSnapshot>
 */
final class YieldSnapshotFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return YieldSnapshot::class;
    }

    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#model-factories
     */
    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'walletAddress' => new WalletAddress('0x'.sha1(self::faker()->uuid())),
            'position' => new VaultPosition(
                shares: new Number('1000000'),
                principal: new Money('1000000', new Currency('USDC')),
                yieldReceived: new Number('1234567890000000'),
                flowRate: new Number('100000000000'),
                connected: true,
                paused: false,
                aprBasisPoints: new Number('300'),
            ),
        ];
    }

    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#initialization
     */
    #[\Override]
    protected function initialize(): static
    {
        // YieldSnapshot's constructor is private — only reachable via its named constructor.
        return $this->instantiateWith(Instantiator::namedConstructor('fromPosition'));
    }
}
