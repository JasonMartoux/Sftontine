<?php

declare(strict_types=1);

namespace App\Application\Identity\Port;

use App\Application\Identity\Exception\PrivyTokenVerificationException;
use App\Application\Identity\PrivyIdentityClaims;

interface PrivyIdentityTokenVerifierInterface
{
    /**
     * @throws PrivyTokenVerificationException
     */
    public function verify(string $identityToken): PrivyIdentityClaims;
}
