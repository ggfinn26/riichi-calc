<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Service\Recommendation;

use Dewa\Mahjong\Entity\GameContext;
use Dewa\Mahjong\Entity\Tile;
use Dewa\Mahjong\Repository\DiscardedPileRepositoryInterface;

/**
 * DefenseEvaluatorService
 * -----------------------------------------------------------------------------
 * Given a candidate tile the player is considering to discard, return a
 * heuristic danger percentage (0.0 … 100.0) against a specific opponent
 * who is assumed to be either tenpai or in riichi.
 *
 * Heuristics implemented (classic defence rules):
 *   1. Genbutsu (現物): the tile is already in the opponent's discards → 0%.
 *   2. Suji (筋): number tile with its "complement" 4-apart tile already in
 *                 the opponent's discards — partial safety. E.g. if 4m is in
 *                 the pile, then 1m and 7m are less risky (ryanmen wait on
 *                 4m is ruled out).
 *   3. Kabe (壁): if three or four copies of a neighbour are visible, certain
 *                 sequence waits involving the candidate become impossible.
 *   4. Default live tile: moderately dangerous (baseline 60%).
 *
 * Output is a coarse heuristic — good enough for UI hinting but NOT a full
 * probabilistic model.
 */
final class DefenseEvaluatorService
{
    public function __construct(
        private readonly DiscardedPileRepositoryInterface $discardRepo,
    ) {}

    /**
     * @return float 0.0 (completely safe) .. 100.0 (very dangerous)
     */
    public function evaluateDangerLevel(Tile $tileToDiscard, GameContext $context, int $targetUserId): float
    {
        $targetDiscards = $this->discardRepo->findByGameContextAndUser(
            $context->getId(),
            $targetUserId
        );

        // 1. Genbutsu — exact match in opponent's discards.
        foreach ($targetDiscards as $d) {
            if ($d->getTile()->getId() === $tileToDiscard->getId()) {
                return 0.0;
            }
        }

        // Honors and non-number tiles cannot be suji/kabe-analyzed.
        if ($tileToDiscard->isHonor()) {
            // Honors: if not genbutsu, use count-based danger.
            //   4 left → dangerous (tanki/shanpon possible)
            //   3+ shown → essentially safe (kabe concept for honors)
            return $this->honorDanger($tileToDiscard, $context);
        }

        // 2. Suji analysis for number tiles.
        $sujiSafety = $this->computeSujiSafety($tileToDiscard, $targetDiscards);
        if ($sujiSafety !== null) {
            return $sujiSafety;
        }

        // 3. Kabe analysis (wall): if ≥3 copies of a critical neighbour are
        //    visible (in any discard pile / melds / dora), we can reduce risk.
        $kabeFactor = $this->computeKabeFactor($tileToDiscard, $context);

        // 4. Baseline danger for a live number tile.
        $baseline = match (true) {
            $tileToDiscard->isTerminal()                  => 35.0,
            (int)$tileToDiscard->getValue() === 2,
            (int)$tileToDiscard->getValue() === 8          => 50.0,
            default                                        => 65.0, // middle tiles (3..7)
        };

        return max(0.0, $baseline * $kabeFactor);
    }

    // ------------------------------------------------------------------
    // Heuristics
    // ------------------------------------------------------------------

    /**
     * Suji: for a middle number X (4,5,6), if X-3 OR X+3 is in opponent's
     * discards the two-sided wait on X becomes impossible.
     * For edge numbers (1,2,3 / 7,8,9), only the single suji partner matters.
     *
     * @param \Dewa\Mahjong\Entity\DiscardedPile[] $targetDiscards
     */
    private function computeSujiSafety(Tile $candidate, array $targetDiscards): ?float
    {
        $suit  = $candidate->getType(); // man/pin/sou
        $value = (int) $candidate->getValue();
        if ($value < 1 || $value > 9) return null;

        // Collect numeric discards of the same suit.
        $discardedValues = [];
        foreach ($targetDiscards as $d) {
            $t = $d->getTile();
            if ($t->getType() === $suit && !$t->isHonor()) {
                $discardedValues[(int) $t->getValue()] = true;
            }
        }

        // For middle tiles (4,5,6): need BOTH suji sides discarded for strong safety.
        if ($value >= 4 && $value <= 6) {
            $left  = $value - 3;
            $right = $value + 3;
            $leftHit  = isset($discardedValues[$left])  && $left  >= 1;
            $rightHit = isset($discardedValues[$right]) && $right <= 9;
            if ($leftHit && $rightHit) return 15.0;          // double suji — fairly safe
            if ($leftHit || $rightHit) return 35.0;          // single suji — partial
            return null;
        }

        // For edge tiles (1,2,3 / 7,8,9): one-sided suji.
        if ($value <= 3) {
            $partner = $value + 3;
            if (isset($discardedValues[$partner])) return 25.0;
            return null;
        }
        // value 7..9
        $partner = $value - 3;
        if (isset($discardedValues[$partner])) return 25.0;
        return null;
    }

    /**
     * Kabe: check whether enough copies (≥3) of a neighbour tile are visible,
     * which eliminates certain sequence waits on the candidate tile.
     * Return a multiplier on the baseline danger: 1.0 = no effect, 0.5 = halved.
     */
    private function computeKabeFactor(Tile $candidate, GameContext $context): float
    {
        $suit  = $candidate->getType();
        $value = (int) $candidate->getValue();
        if ($value < 1 || $value > 9) return 1.0;

        // Build a visibility count map for this suit.
        $visible = []; // value => count
        $addVisible = function ($t) use (&$visible, $suit) {
            if ($t->getType() === $suit && !$t->isHonor()) {
                $v = (int) $t->getValue();
                $visible[$v] = ($visible[$v] ?? 0) + 1;
            }
        };

        foreach ($context->getDiscardPile() as $d) $addVisible($d->getTile());
        foreach ($context->getHands() as $hand) {
            foreach ($hand->getMelds() as $meld) {
                foreach ($meld->getTiles() as $t) $addVisible($t);
            }
        }
        foreach ($context->getDoraIndicators() as $t) $addVisible($t);

        // If BOTH neighbours (value-1 and value+1) are ≥3 visible, ryanmen
        // and kanchan through candidate are essentially dead.
        $leftBlocked  = (($visible[$value - 1] ?? 0) >= 3);
        $rightBlocked = (($visible[$value + 1] ?? 0) >= 3);

        if ($leftBlocked && $rightBlocked) return 0.3;
        if ($leftBlocked || $rightBlocked) return 0.7;
        return 1.0;
    }

    /**
     * Honor-tile danger: check how many copies are visible across the table.
     * If 3+ are visible, the remaining 1 can only form a tanki wait — moderate risk.
     */
    private function honorDanger(Tile $candidate, GameContext $context): float
    {
        $id = $candidate->getId();
        $visible = 0;

        foreach ($context->getDiscardPile() as $d) {
            if ($d->getTile()->getId() === $id) $visible++;
        }
        foreach ($context->getHands() as $hand) {
            foreach ($hand->getMelds() as $meld) {
                foreach ($meld->getTiles() as $t) {
                    if ($t->getId() === $id) $visible++;
                }
            }
        }
        foreach ($context->getDoraIndicators() as $t) {
            if ($t->getId() === $id) $visible++;
        }

        return match (true) {
            $visible >= 3 => 20.0, // only 1 copy left → tanki-only wait
            $visible === 2 => 40.0,
            $visible === 1 => 55.0,
            default        => 70.0, // all 4 still possibly held
        };
    }
}
