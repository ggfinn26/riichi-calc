<?php
declare(strict_types=1);

namespace Dewa\Mahjong\DTO;

/** DTO for POST /api/action/draw */
final class DrawActionRequest
{
    private function __construct(
        public readonly int $gameContextId,
        public readonly int $userId,
        public readonly int $tileId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            gameContextId: self::requirePositiveInt($data, 'game_context_id'),
            userId:        self::requirePositiveInt($data, 'user_id'),
            tileId:        self::requireTileId($data, 'tile_id'),
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

    private static function requireTileId(array $data, string $key): int
    {
        $id = self::requirePositiveInt($data, $key);
        if ($id < 1 || $id > 34) {
            throw new \InvalidArgumentException("Field '{$key}' must be a valid tile ID (1–34), got {$id}.");
        }
        return $id;
    }
}
