<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Service\Calculation;

use Dewa\Mahjong\Entity\Hand;
use Dewa\Mahjong\Entity\Tile;
use Dewa\Mahjong\Repository\DiscardedPileRepositoryInterface;

/**
 * FuritenChecker
 * -----------------------------------------------------------------------------
 * Evaluate furiten (振聴) — a rule forbidding a player from winning via Ron
 * when any of their waiting tiles has already been discarded by themselves
 * or passed up on the current turn / after declaring Riichi.
 *
 * Furiten kinds:
 *   1. Permanent Furiten (Sutehai Furiten):
 *      Any of the player's own previous discards matches one of their waits.
 *   2. Temporary Furiten (Dou-jun Furiten):
 *      The player missed a Ron opportunity (any opponent discard in this turn
 *      rotation matched a wait). Cleared only by drawing their own next tile.
 *      In this service we expose it via the `missedRonThisTurn` flag.
 *   3. Riichi Furiten:
 *      After declaring Riichi, any missed Ron becomes permanent for the round.
 *
 * Stateless service — the full history lives in DiscardedPileRepository.
 */
final class FuritenChecker
{
    public function __construct(
        private readonly DiscardedPileRepositoryInterface $discardRepo,
    ) {}

    /**
     * Check whether the player is currently in furiten.
     *
     * @param Hand   $hand               player's hand (knows user_id, riichi status, game_context_id)
     * @param Tile[] $waits              waiting tiles (output of WaitCalculator)
     * @param bool   $missedRonThisTurn  true if the player just passed on a winning
     *                                   opportunity this turn rotation (temporary furiten trigger)
     */
    public function isFuriten(Hand $hand, array $waits, bool $missedRonThisTurn = false): bool
    {
        if ($waits === []) {
            // No waits → not tenpai → furiten concept doesn't apply
            return false;
        }

        // Build a lookup of waiting tile IDs for O(1) membership checking.
        $waitIds = [];
        foreach ($waits as $tile) {
            $waitIds[$tile->getId()] = true;
        }

        // 1. Permanent Furiten (and Riichi Furiten share the same discard scan).
        $ownDiscards = $this->discardRepo->findByGameContextAndUser(
            $hand->getGameContextId(),
            $hand->getUserId()
        );

        foreach ($ownDiscards as $discard) {
            if (isset($waitIds[$discard->getTile()->getId()])) {
                // Player has previously discarded a tile identical to one of their waits.
                // This state is sticky: if Riichi was declared it remains until round end.
                return true;
            }
        }

        // 2. Temporary Furiten — missed ron this turn rotation.
        if ($missedRonThisTurn) {
            return true;
        }

        // 3. Riichi Furiten is also triggered by missed ron after Riichi, but because
        //    declareRiichi() is an irrevocable state and any missed ron locks furiten
        //    for the rest of the round, callers should set $missedRonThisTurn = true
        //    for the remainder of the round once a miss happened under Riichi.
        //    This service trusts the caller to persist that flag; nothing to check here.

        return false;
    }
}
