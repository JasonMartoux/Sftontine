<?php

declare(strict_types=1);

namespace App\Application\Tontine\Port;

use App\Domain\Tontine\TontineGroup;

interface TontineGroupRepositoryInterface
{
    public function save(TontineGroup $group): void;

    public function find(int $id): ?TontineGroup;

    /**
     * @return list<TontineGroup>
     */
    public function findAllForUserId(int $userId): array;
}
