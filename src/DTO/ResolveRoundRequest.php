<?php
declare(strict_types=1);

namespace Dewa\Mahjong\DTO;

/** DTO for POST /api/round/resolve */
final class ResolveRoundRequest
{
    private const VALID_END_TYPES = ['agari', 'ryuukyoku', 'chombo', 'suukaikan'];

    private function __construct(
        public readonly int     $gameContextId,
        public readonly string  $endType,
        public readonly ?int    $winnerUserId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $gameContextId = self::requirePositiveInt($data, 'game_context_id');

        if (!isset($data['end_type']) || !is_string($data['end_type'])) {
            throw new \InvalidArgumentException("'end_type' is required.");
        }
        $endType = strtolower(trim($data['end_type']));
        if (!in_array($endType, self::VALID_END_TYPES, true)) {
            throw new \InvalidArgumentException(
                "'end_type' must be one of: " . implode(', ', self::VALID_END_TYPES)
            );
        }

        // winner_user_id is required only for 'agari'
        $winnerUserId = null;
        if ($endType === 'agari') {
            if (!isset($data['winner_user_id']) || !is_numeric($data['winner_user_id'])) {
                throw new \InvalidArgumentException(
                    "'winner_user_id' is required when end_type is 'agari'."
                );
            }
            $winnerUserId = (int) $data['winner_user_id'];
            if ($winnerUserId <= 0) {
                throw new \InvalidArgumentException("'winner_user_id' must be > 0.");
            }
        }

        return new self(
            gameContextId: $gameContextId,
            endType:       $endType,
            winnerUserId:  $winnerUserId,
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
