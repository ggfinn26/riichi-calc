<?php
declare(strict_types=1);

namespace Dewa\Mahjong\DTO;

/**
 * DTO for POST /api/score/waits
 */
final class WaitsRequest
{
    private function __construct(
        public readonly int $handId,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        if (!array_key_exists('hand_id', $data) || !is_numeric($data['hand_id'])) {
            throw new \InvalidArgumentException("Required field 'hand_id' must be an integer.");
        }
        $handId = (int) $data['hand_id'];
        if ($handId <= 0) {
            throw new \InvalidArgumentException("Field 'hand_id' must be > 0, got {$handId}.");
        }
        return new self(handId: $handId);
    }
}
