<?php

declare(strict_types=1);

namespace App\Tests\Factory\Tontine;

use App\Application\Tontine\Port\TontineGroupRepositoryInterface;
use App\Domain\Identity\WalletAddress;
use App\Domain\Tontine\Periodicity;
use App\Domain\Tontine\TontineGroup;
use App\Tests\Factory\Identity\UserFactory;
use Money\Currency;
use Money\Money;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<TontineGroup>
 */
final class TontineGroupFactory extends PersistentObjectFactory
{
    public function __construct(
        private readonly TontineGroupRepositoryInterface $groups,
    ) {
    }

    #[\Override]
    public static function class(): string
    {
        return TontineGroup::class;
    }

    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#model-factories
     */
    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'creator' => UserFactory::new(),
            'name' => 'Tontine '.self::faker()->word(),
            'contributionAmount' => new Money('25000000', new Currency('USDC')),
            'periodicity' => Periodicity::Weekly,
            'installmentsPerCycle' => 12,
            'now' => new \DateTimeImmutable(),
        ];
    }

    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#initialization
     */
    #[\Override]
    protected function initialize(): static
    {
        // TontineGroup's constructor is private — only reachable via its named constructor.
        // Doctrine doesn't cascade-persist memberships/cycles (plain arrays, not mapped
        // OneToMany — see TontineGroup's class docblock), so the default single-entity
        // persist Foundry does isn't enough: re-run the real save() to persist them too.
        return $this->instantiateWith(Instantiator::namedConstructor('create'))
            ->afterInstantiate(function (TontineGroup $group): void {
                // Provision a default Safe address so tests that exercise the contribution form work
                $group->provisionSafe(new WalletAddress('0x'.str_repeat('0', 40)));
            })
            ->afterPersist(function (TontineGroup $group): void {
                $this->groups->save($group);
            });
    }
}
