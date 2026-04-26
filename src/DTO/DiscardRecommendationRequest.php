<?php
declare(strict_types=1);

namespace Dewa\Mahjong\DTO;

/**
 * DTO for POST /api/recommendation/discard
 */
final class DiscardRecommendationRequest
{
    private function __construct(
        public readonly int   $handId,
        public readonly int   $gameContextId,
        /** @var int[] */
        public readonly array $threateningUserIds,
        /** @var array{speed?:float,defense?:float,value?:float} */
        public readonly array $weights,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        $handId        = self::requirePositiveInt($data, 'hand_id');
        $gameContextId = self::requirePositiveInt($data, 'game_context_id');

        $threats = [];
        if (isset($data['threatening_user_ids'])) {
            if (!is_array($data['threatening_user_ids'])) {
                throw new \InvalidArgumentException("'threatening_user_ids' must be an array of integers.");
            }
            foreach ($data['threatening_user_ids'] as $uid) {
                if (!is_numeric($uid) || (int) $uid <= 0) {
                    throw new \InvalidArgumentException("'threatening_user_ids' entries must be positive integers.");
                }
                $threats[] = (int) $uid;
            }
        }

        $weights = [];
        if (isset($data['weights'])) {
            if (!is_array($data['weights'])) {
                throw new \InvalidArgumentException("'weights' must be an object with optional speed/defense/value keys.");
            }
            foreach (['speed', 'defense', 'value'] as $k) {
                if (isset($data['weights'][$k])) {
                    if (!is_numeric($data['weights'][$k])) {
                        throw new \InvalidArgumentException("Weight '{$k}' must be numeric.");
                    }
                    $weights[$k] = (float) $data['weights'][$k];
                }
            }
        }

        return new self(
            handId:              $handId,
            gameContextId:       $gameContextId,
            threateningUserIds:  $threats,
            weights:             $weights,
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
