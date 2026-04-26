<?php
declare(strict_types=1);

namespace Dewa\Mahjong\DTO;

/** DTO for POST /api/action/riichi */
final class RiichiActionRequest
{
    private function __construct(
        public readonly int $handId,
        public readonly int $discardActionId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            handId:          self::requirePositiveInt($data, 'hand_id'),
            discardActionId: self::requirePositiveInt($data, 'discard_action_id'),
        );
    }

    private static function requirePositiveInt(array $data, string $key): int
    {
        if (!array_key_exists($key, $data) || !is_numeric($data[$key])) {
            throw new \InvalidArgumentException("Required field '{$key}' must be an integer.");
        }
        $val = (int) $data[$key];
        if ($val <= 0) throw new \InvalidArgumentException("Field '{$key}' must be > 0.");
        return $val;
    }
}
