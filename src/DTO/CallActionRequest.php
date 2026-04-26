<?php
declare(strict_types=1);

namespace Dewa\Mahjong\DTO;

/** DTO for POST /api/action/call (chi / pon / kan) */
final class CallActionRequest
{
    private const VALID_MELD_TYPES = ['chi', 'pon', 'daiminkan', 'ankan', 'shouminkan'];

    private function __construct(
        public readonly int    $handId,
        public readonly string $meldType,
        public readonly int    $calledTileId,
        /** @var int[] */
        public readonly array  $tileIdsFromHand,
        public readonly bool   $isClosed,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $handId       = self::requirePositiveInt($data, 'hand_id');
        $calledTileId = self::requireTileId($data, 'called_tile_id');

        // meld_type enum
        if (!isset($data['meld_type']) || !is_string($data['meld_type'])) {
            throw new \InvalidArgumentException("'meld_type' is required.");
        }
        $meldType = strtolower(trim($data['meld_type']));
        if (!in_array($meldType, self::VALID_MELD_TYPES, true)) {
            throw new \InvalidArgumentException(
                "'meld_type' must be one of: " . implode(', ', self::VALID_MELD_TYPES)
            );
        }

        // tile_ids_from_hand: array of 2–3 valid tile IDs
        $tileIdsRaw = $data['tile_ids_from_hand'] ?? [];
        if (!is_array($tileIdsRaw) || count($tileIdsRaw) < 2 || count($tileIdsRaw) > 3) {
            throw new \InvalidArgumentException(
                "'tile_ids_from_hand' must be an array of 2 or 3 tile IDs."
            );
        }
        $tileIdsFromHand = [];
        foreach ($tileIdsRaw as $i => $tid) {
            if (!is_numeric($tid) || (int)$tid < 1 || (int)$tid > 34) {
                throw new \InvalidArgumentException(
                    "'tile_ids_from_hand[{$i}]' must be a valid tile ID (1–34)."
                );
            }
            $tileIdsFromHand[] = (int) $tid;
        }

        return new self(
            handId:          $handId,
            meldType:        $meldType,
            calledTileId:    $calledTileId,
            tileIdsFromHand: $tileIdsFromHand,
            isClosed:        (bool) ($data['is_closed'] ?? false),
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
