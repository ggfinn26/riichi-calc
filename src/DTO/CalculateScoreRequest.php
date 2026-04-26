<?php
declare(strict_types=1);

namespace Dewa\Mahjong\DTO;

/**
 * DTO for POST /api/score/calculate
 *
 * Validates and wraps all fields required by ScoringService::calculate().
 */
final class CalculateScoreRequest
{
    private function __construct(
        public readonly int     $gameContextId,
        public readonly int     $handId,
        public readonly int     $winningTileId,
        public readonly bool    $isTsumo,
        /** @var int[] */
        public readonly array   $yakuIds,
        public readonly int     $doraCount,
        public readonly bool    $isPinfu,
        public readonly int     $honbaCount,
        public readonly int     $riichiSticks,
        public readonly ?int    $paoPlayerId,
        public readonly ?string $paoYakumanType,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @throws \InvalidArgumentException on validation failure
     */
    public static function fromArray(array $data): self
    {
        $gameContextId = self::requirePositiveInt($data, 'game_context_id');
        $handId        = self::requirePositiveInt($data, 'hand_id');
        $winningTileId = self::requireTileId($data, 'winning_tile_id');
        $isTsumo       = (bool) ($data['is_tsumo'] ?? false);
        $isPinfu       = (bool) ($data['is_pinfu'] ?? false);
        $doraCount     = self::requireIntInRange($data, 'dora_count', 0, 12, optional: true, default: 0);
        $honbaCount    = self::requireIntInRange($data, 'honba_count', 0, 99, optional: true, default: 0);
        $riichiSticks  = self::requireIntInRange($data, 'riichi_sticks', 0, 4, optional: true, default: 0);

        // yaku_ids: array of positive ints, at least 1 item
        $yakuIds = $data['yaku_ids'] ?? [];
        if (!is_array($yakuIds) || count($yakuIds) === 0) {
            throw new \InvalidArgumentException("'yaku_ids' must be a non-empty array of integer IDs.");
        }
        $yakuIds = array_map(fn($id) => (int) $id, $yakuIds);

        // pao_player_id: optional positive int
        $paoPlayerId = null;
        if (isset($data['pao_player_id']) && $data['pao_player_id'] !== null) {
            $paoPlayerId = (int) $data['pao_player_id'];
            if ($paoPlayerId <= 0) {
                throw new \InvalidArgumentException("'pao_player_id' must be a positive integer.");
            }
        }

        // pao_yakuman_type: optional enum
        $paoYakumanType = null;
        if (isset($data['pao_yakuman_type']) && $data['pao_yakuman_type'] !== null) {
            $allowed = ['daisangen', 'daisuushii', 'suukantsu'];
            $paoYakumanType = (string) $data['pao_yakuman_type'];
            if (!in_array($paoYakumanType, $allowed, true)) {
                throw new \InvalidArgumentException(
                    "'pao_yakuman_type' must be one of: " . implode(', ', $allowed)
                );
            }
        }

        return new self(
            gameContextId:  $gameContextId,
            handId:         $handId,
            winningTileId:  $winningTileId,
            isTsumo:        $isTsumo,
            yakuIds:        $yakuIds,
            doraCount:      $doraCount,
            isPinfu:        $isPinfu,
            honbaCount:     $honbaCount,
            riichiSticks:   $riichiSticks,
            paoPlayerId:    $paoPlayerId,
            paoYakumanType: $paoYakumanType,
        );
    }

    // -----------------------------------------------------------------------
    // Shared validators (private static helpers — no base class needed)
    // -----------------------------------------------------------------------

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

    private static function requireTileId(array $data, string $key): int
    {
        $id = self::requirePositiveInt($data, $key);
        if ($id < 1 || $id > 34) {
            throw new \InvalidArgumentException(
                "Field '{$key}' must be a valid tile ID (1–34), got {$id}."
            );
        }
        return $id;
    }

    private static function requireIntInRange(
        array  $data,
        string $key,
        int    $min,
        int    $max,
        bool   $optional = false,
        int    $default = 0
    ): int {
        if (!array_key_exists($key, $data)) {
            if ($optional) return $default;
            throw new \InvalidArgumentException("Required field '{$key}' is missing.");
        }
        if (!is_numeric($data[$key])) {
            throw new \InvalidArgumentException("Field '{$key}' must be an integer.");
        }
        $val = (int) $data[$key];
        if ($val < $min || $val > $max) {
            throw new \InvalidArgumentException(
                "Field '{$key}' must be between {$min} and {$max}, got {$val}."
            );
        }
        return $val;
    }
}
