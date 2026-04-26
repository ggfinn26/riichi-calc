<?php
declare(strict_types=1);

namespace Dewa\Mahjong\DTO;

/**
 * DTO for POST /api/recommendation/defense
 *
 * Accepts either a single tile_id or an array of tile_ids to evaluate
 * against one threatening opponent.
 */
final class DefenseEvaluationRequest
{
    private function __construct(
        public readonly int   $gameContextId,
        public readonly int   $targetUserId,
        /** @var int[] */
        public readonly array $tileIds,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        $gameContextId = self::requirePositiveInt($data, 'game_context_id');
        $targetUserId  = self::requirePositiveInt($data, 'target_user_id');

        $tileIds = [];
        if (isset($data['tile_ids']) && is_array($data['tile_ids'])) {
            foreach ($data['tile_ids'] as $id) {
                $tileIds[] = self::validateTileId((int) $id);
            }
        } elseif (isset($data['tile_id'])) {
            $tileIds[] = self::validateTileId((int) $data['tile_id']);
        } else {
            throw new \InvalidArgumentException("Either 'tile_id' or 'tile_ids' is required.");
        }

        if ($tileIds === []) {
            throw new \InvalidArgumentException("At least one tile_id is required.");
        }

        return new self(
            gameContextId: $gameContextId,
            targetUserId:  $targetUserId,
            tileIds:       $tileIds,
        );
    }

    private static function validateTileId(int $id): int
    {
        if ($id < 1 || $id > 34) {
            throw new \InvalidArgumentException("Tile id must be 1..34, got {$id}.");
        }
        return $id;
    }

    private static function requirePositiveInt(array $data, string $key): int
    {
        if (!array_key_exists($key, $data) || !is_numeric($data[$key])) {
            throw new \InvalidArgumentException("Required field '{$key}' must be an integer.");
        }
        $val = (int) $data[$key];
        if ($val <= 0) {
            throw new \InvalidArgumentException("Field '{$key}' must be > 0, got {$val}.");
        }
        return $val;
    }
}
