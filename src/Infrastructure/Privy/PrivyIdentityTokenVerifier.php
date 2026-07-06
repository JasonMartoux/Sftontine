<?php

declare(strict_types=1);

namespace App\Infrastructure\Privy;

use App\Application\Identity\Exception\PrivyTokenVerificationException;
use App\Application\Identity\Port\PrivyIdentityTokenVerifierInterface;
use App\Application\Identity\PrivyIdentityClaims;
use App\Domain\Identity\Exception\InvalidWalletAddressException;
use App\Domain\Identity\WalletAddress;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Verifies a Privy identity token (distinct from the access token) and extracts
 * the user data it carries via its `linked_accounts` claim: email and embedded
 * Ethereum wallet address, both authenticated by Privy rather than client-supplied.
 */
final readonly class PrivyIdentityTokenVerifier implements PrivyIdentityTokenVerifierInterface
{
    private const ISSUER = 'privy.io';

    public function __construct(
        private string $privyAppId,
        private string $verificationKey,
    ) {
    }

    public function verify(string $identityToken): PrivyIdentityClaims
    {
        try {
            $claims = JWT::decode($identityToken, new Key($this->verificationKey, 'ES256'));
        } catch (ExpiredException) {
            throw PrivyTokenVerificationException::expired();
        } catch (\Throwable $e) {
            throw PrivyTokenVerificationException::malformed($e->getMessage());
        }

        $issuer = $claims->iss ?? null;
        if (!\is_string($issuer) || self::ISSUER !== $issuer) {
            throw PrivyTokenVerificationException::invalidIssuer(\is_string($issuer) ? $issuer : '');
        }

        $audience = $claims->aud ?? null;
        if (!\is_string($audience) || $this->privyAppId !== $audience) {
            throw PrivyTokenVerificationException::invalidAudience(\is_string($audience) ? $audience : '');
        }

        $subject = $claims->sub ?? null;
        if (!\is_string($subject)) {
            throw PrivyTokenVerificationException::malformed('Missing "sub" claim.');
        }

        $linkedAccounts = self::decodeLinkedAccounts($claims->linked_accounts ?? null);

        $email = self::findAccountField($linkedAccounts, 'email', 'address');
        $walletAddressValue = self::findAccountField(
            $linkedAccounts,
            'wallet',
            'address',
            static fn (array $account): bool => 'ethereum' === ($account['chain_type'] ?? null),
        );

        if (null === $walletAddressValue) {
            throw PrivyTokenVerificationException::missingWalletAddress();
        }

        try {
            $walletAddress = new WalletAddress($walletAddressValue);
        } catch (InvalidWalletAddressException $e) {
            throw PrivyTokenVerificationException::malformed($e->getMessage());
        }

        return new PrivyIdentityClaims($subject, $email, $walletAddress);
    }

    /**
     * @return list<mixed>
     */
    private static function decodeLinkedAccounts(mixed $raw): array
    {
        if (!\is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * @param list<mixed> $linkedAccounts
     */
    private static function findAccountField(array $linkedAccounts, string $type, string $field, ?callable $extraFilter = null): ?string
    {
        foreach ($linkedAccounts as $account) {
            if (!\is_array($account) || ($account['type'] ?? null) !== $type) {
                continue;
            }
            if (null !== $extraFilter && !$extraFilter($account)) {
                continue;
            }

            $value = $account[$field] ?? null;

            return \is_string($value) ? $value : null;
        }

        return null;
    }
}
