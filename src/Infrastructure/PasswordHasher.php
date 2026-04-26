<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Infrastructure;

/**
 * PasswordHasher
 * -----------------------------------------------------------------------------
 * Wraps PHP's password_hash/password_verify with an Argon2id-preferred policy
 * and an automatic bcrypt fallback when the runtime was not compiled with
 * libsodium/argon2 support.
 *
 * Verification always uses {@see password_verify()}, which auto-detects the
 * algorithm from the stored hash prefix ($argon2id$, $2y$, etc.), so legacy
 * bcrypt hashes keep working even after the policy upgrades to Argon2id.
 */
final class PasswordHasher
{
    /**
     * Hash a plaintext password using the strongest algorithm available.
     */
    public function hash(string $plain): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            $hash = @password_hash($plain, PASSWORD_ARGON2ID);
            if (is_string($hash) && $hash !== '') {
                return $hash;
            }
        }

        $hash = password_hash($plain, PASSWORD_BCRYPT);
        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('Password hashing failed.');
        }
        return $hash;
    }

    /**
     * Verify a plaintext password against any supported hash format.
     */
    public function verify(string $plain, string $hash): bool
    {
        if ($hash === '') return false;
        return password_verify($plain, $hash);
    }

    /**
     * Detect whether the existing hash should be re-hashed using a stronger
     * algorithm (e.g. legacy bcrypt → Argon2id).
     */
    public function needsRehash(string $hash): bool
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_needs_rehash($hash, PASSWORD_ARGON2ID);
        }
        return password_needs_rehash($hash, PASSWORD_BCRYPT);
    }

    /**
     * Algorithm identifier used by the most recent hash() invocation.
     * Useful for logging / diagnostics.
     */
    public function preferredAlgorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') ? 'argon2id' : 'bcrypt';
    }
}
