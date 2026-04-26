<?php

namespace Dewa\Mahjong\Repository;

use Dewa\Mahjong\Entity\Hand;

interface HandRepositoryInterface{
    public function findById(int $id): ?Hand;
    public function findByGameContextId(int $gameContextId): array;
    public function findByGameContextAndUser(int $gameContextId, int $userId): ?Hand;

    /**
     * History summary for a user across all rounds they have played.
     *
     * Returns shallow associative rows (no deep hydration) suitable for a
     * paginated list view. Ordered by round most-recent first.
     *
     * @return list<array{
     *   hand_id: int,
     *   game_context_id: int,
     *   game_id: int,
     *   round_number: int,
     *   round_wind: string,
     *   status: string,
     *   is_dealer: bool,
     *   is_riichi_declared: bool
     * }>
     */
    public function findHistoryByUserId(int $userId): array;

    public function save(Hand $hand): Hand;
    public function replaceTiles(int $handId, array $tiles): void;
    public function setRiichiDiscard(int $handId, int $discardActionId): void;
    public function setNagashiManganDiscard(int $handId, int $discardActionId): void;
    
}