<?php
declare(strict_types=1);

namespace Dewa\Mahjong\DTO;

/**
 * DTO for POST /api/auth/login
 *
 * Identifier may be either a username or an email — the controller resolves
 * which by checking the format.
 */
final class LoginRequest
{
    private function __construct(
        public readonly string $identifier,
        public readonly string $password,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        $identifier = $data['identifier'] ?? $data['email'] ?? $data['username'] ?? null;
        if (!is_string($identifier) || trim($identifier) === '') {
            throw new \InvalidArgumentException(
                "Required field 'identifier' (or 'email'/'username') must be a non-empty string."
            );
        }

        if (!isset($data['password']) || !is_string($data['password']) || $data['password'] === '') {
            throw new \InvalidArgumentException("Required field 'password' must be a non-empty string.");
        }

        return new self(
            identifier: trim($identifier),
            password:   $data['password'],
        );
    }
}
