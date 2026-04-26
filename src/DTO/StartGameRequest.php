<?php
declare(strict_types=1);

namespace Dewa\Mahjong\DTO;

/**
 * DTO for POST /api/game/start
 *
 * Initialises a new GameContext (round) for an existing game.
 */
final class StartGameRequest
{
    private function __construct(
        public readonly int    $gameId,
        public readonly int    $dealerId,
        public readonly int    $roundNumber,
        public readonly string $roundWind,
        public readonly bool   $isAkaAri,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        $gameId   = self::requirePositiveInt($data, 'game_id');
        $dealerId = self::requirePositiveInt($data, 'dealer_id');

        $roundNumber = (int) ($data['round_number'] ?? 1);
        if ($roundNumber < 1 || $roundNumber > 4) {
            throw new \InvalidArgumentException("Field 'round_number' must be 1..4, got {$roundNumber}.");
        }

        $roundWind = (string) ($data['round_wind'] ?? 'east');
        $allowedWinds = ['east', 'south', 'west', 'north'];
        if (!in_array($roundWind, $allowedWinds, true)) {
            throw new \InvalidArgumentException(
                "Field 'round_wind' must be one of: " . implode(', ', $allowedWinds)
            );
        }

        $isAkaAri = (bool) ($data['is_aka_ari'] ?? true);

        return new self(
            gameId:      $gameId,
            dealerId:    $dealerId,
            roundNumber: $roundNumber,
            roundWind:   $roundWind,
            isAkaAri:    $isAkaAri,
        );
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
