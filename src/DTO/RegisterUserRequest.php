<?php
declare(strict_types=1);

namespace Dewa\Mahjong\DTO;

/**
 * DTO for POST /api/users
 */
final class RegisterUserRequest
{
    private function __construct(
        public readonly string $username,
        public readonly string $email,
        public readonly string $password,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        $username = self::requireString($data, 'username', 3, 32);
        $email    = self::requireEmail($data, 'email');
        $password = self::requireString($data, 'password', 8, 128);

        return new self(
            username: $username,
            email:    $email,
            password: $password,
        );
    }

    private static function requireString(array $data, string $key, int $min, int $max): string
    {
        if (!array_key_exists($key, $data) || !is_string($data[$key])) {
            throw new \InvalidArgumentException("Required field '{$key}' must be a string.");
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

    private static function requireEmail(array $data, string $key): string
    {
        $val = self::requireString($data, $key, 3, 254);
        if (filter_var($val, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException("Field '{$key}' must be a valid email.");
        }
        return $val;
    }
}
