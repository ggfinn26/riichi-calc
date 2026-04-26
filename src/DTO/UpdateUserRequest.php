<?php
declare(strict_types=1);

namespace Dewa\Mahjong\DTO;

/**
 * DTO for PUT /api/users/{id}
 *
 * All fields are optional, but at least one of (username, email, password)
 * must be provided. When 'password' is set, 'current_password' is required
 * so the controller can verify the caller knows the existing credential.
 */
final class UpdateUserRequest
{
    private function __construct(
        public readonly ?string $username,
        public readonly ?string $email,
        public readonly ?string $newPassword,
        public readonly ?string $currentPassword,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        $username    = self::optionalString($data, 'username', 3, 32);
        $email       = self::optionalEmail($data, 'email');
        $newPassword = self::optionalString($data, 'password', 8, 128);

        if ($username === null && $email === null && $newPassword === null) {
            throw new \InvalidArgumentException(
                "Provide at least one of: 'username', 'email', 'password'."
            );
        }

        $currentPassword = null;
        if ($newPassword !== null) {
            if (!isset($data['current_password']) || !is_string($data['current_password'])) {
                throw new \InvalidArgumentException(
                    "'current_password' is required when changing 'password'."
                );
            }
            $currentPassword = (string) $data['current_password'];
            if ($currentPassword === '') {
                throw new \InvalidArgumentException("'current_password' must not be empty.");
            }
        }

        return new self(
            username:        $username,
            email:           $email,
            newPassword:     $newPassword,
            currentPassword: $currentPassword,
        );
    }

    private static function optionalString(array $data, string $key, int $min, int $max): ?string
    {
        if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            return null;
        }
        if (!is_string($data[$key])) {
            throw new \InvalidArgumentException("Field '{$key}' must be a string.");
        }
        $val = trim($data[$key]);
        $len = strlen($val);
        if ($len < $min || $len > $max) {
            throw new \InvalidArgumentException(
                "Field '{$key}' length must be between {$min} and {$max}, got {$len}."
            );
        }
        return $val;
    }

    private static function optionalEmail(array $data, string $key): ?string
    {
        $val = self::optionalString($data, $key, 3, 254);
        if ($val === null) return null;
        if (filter_var($val, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException("Field '{$key}' must be a valid email.");
        }
        return $val;
    }
}
