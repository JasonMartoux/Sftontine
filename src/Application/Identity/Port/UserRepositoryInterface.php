<?php

declare(strict_types=1);

namespace App\Application\Identity\Port;

use App\Domain\Identity\User;

interface UserRepositoryInterface
{
    public function findByPrivySubjectId(string $subjectId): ?User;

    public function save(User $user): void;

    /**
     * @return list<User>
     */
    public function findAll(): array;
}
