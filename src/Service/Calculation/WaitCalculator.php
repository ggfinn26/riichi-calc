<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Service\Calculation;

use Dewa\Mahjong\Entity\Hand;
use Dewa\Mahjong\Entity\Tile;
use Dewa\Mahjong\Repository\TileRepositoryInterface;

/**
 * WaitCalculator (Machi Detector)
 * -----------------------------------------------------------------------------
 * Stateless service that, given a 13-tile (post-meld) hand, returns every tile
 * that would complete the hand into a winning 14-tile shape.
 *
 * Strategy:
 *   For each of the 34 tile kinds, tentatively add it to the hand's frequency
 *   map and test whether the resulting 14 tiles can be decomposed into a
 *   standard shape (4 sets + 1 pair), a Chiitoitsu (7 pairs) or a Kokushi
 *   Musou (thirteen orphans) pattern. Any tile kind that yields at least one
 *   valid decomposition is a valid wait.
 *
 * The decomposition algorithm mirrors YakuEvaluator::calculateStandardCombinations
 * but short-circuits as soon as one valid composition is found.
 */
final class WaitCalculator
{
    public function __construct(
        private readonly TileRepositoryInterface $tileRepo,
    ) {}

    /**
     * @return Tile[] list of tiles that complete the hand (machi)
     */
    public function calculateWaits(Hand $hand): array
    {
        // Must be in a tenpai-eligible shape:
        //   - Total logical tiles = 13 (open melds counted as 3 each)
        //   - Physical tiles ≤ 13 (ignoring extras from Kan, which are logically still 1 set)
        if ($hand->getTotalTilesCount() !== 13) {
            return [];
        }

        // Build closed-hand frequency map (only tiles still in the hand — melds are done).
        $baseFreq = [];
        foreach ($hand->getTiles() as $t) {
            $id = $t->getId();
            $baseFreq[$id] = ($baseFreq[$id] ?? 0) + 1;
        }

        $meldSetCount = count($hand->getMelds()); // each open meld counts as 1 completed set

        $waits = [];
        foreach ($this->tileRepo->findAll() as $candidate) {
            // Max 4 copies per tile kind globally. If the hand already holds 4,
            // that tile cannot be a new wait.
            $currentCount = $baseFreq[$candidate->getId()] ?? 0;
            if ($currentCount >= 4) {
                continue;
            }

            $trialFreq = $baseFreq;
            $trialFreq[$candidate->getId()] = $currentCount + 1;

            if ($this->canFormWinning($trialFreq, $meldSetCount)) {
                $waits[] = $candidate;
            }
        }

        return $waits;
    }

    /**
     * Check if the tile frequency map (combined with already-declared melds)
     * produces at least one valid 14-tile winning shape.
     *
     * @param array<int,int> $freqMap
     */
    private function canFormWinning(array $freqMap, int $meldSetCount): bool
    {
        // Kokushi Musou and Chiitoitsu require a fully concealed 14-tile hand (no melds).
        if ($meldSetCount === 0) {
            if ($this->isChiitoitsu($freqMap)) return true;
            if ($this->isKokushi($freqMap))    return true;
        }

        // Standard: need (4 - $meldSetCount) sets + 1 pair from the closed tiles.
        $neededSets = 4 - $meldSetCount;
        return $this->canDecomposeStandard($freqMap, $neededSets, false);
    }

    /**
     * Recursive backtracking: try to consume every tile into (pair + $neededSets sets).
     *
     * @param array<int,int> $freqMap
     */
    private function canDecomposeStandard(array $freqMap, int $neededSets, bool $hasPair): bool
    {
        // Find first remaining tile.
        $firstId = -1;
        foreach ($freqMap as $id => $count) {
            if ($count > 0) { $firstId = $id; break; }
        }

        // Base: no tiles left → success iff we already formed a pair and all needed sets.
        if ($firstId === -1) {
            return $hasPair && $neededSets === 0;
        }

        // If we already have every set filled but still tiles remain, fail fast.
        if ($neededSets < 0) {
            return false;
        }

        // Route A: take pair from current tile.
        if (!$hasPair && $freqMap[$firstId] >= 2) {
            $freqMap[$firstId] -= 2;
            if ($this->canDecomposeStandard($freqMap, $neededSets, true)) {
                return true;
            }
            $freqMap[$firstId] += 2;
        }

        // Route B: triplet (koutsu).
        if ($neededSets > 0 && $freqMap[$firstId] >= 3) {
            $freqMap[$firstId] -= 3;
            if ($this->canDecomposeStandard($freqMap, $neededSets - 1, $hasPair)) {
                return true;
            }
            $freqMap[$firstId] += 3;
        }

        // Route C: sequence (shuntsu). Only number suits, and only starting 1..7 of each suit.
        if ($neededSets > 0 && $this->canStartSequence($firstId) &&
            ($freqMap[$firstId + 1] ?? 0) >= 1 &&
            ($freqMap[$firstId + 2] ?? 0) >= 1)
        {
            $freqMap[$firstId]--;
            $freqMap[$firstId + 1]--;
            $freqMap[$firstId + 2]--;
            if ($this->canDecomposeStandard($freqMap, $neededSets - 1, $hasPair)) {
                return true;
            }
            $freqMap[$firstId]++;
            $freqMap[$firstId + 1]++;
            $freqMap[$firstId + 2]++;
        }

        return false;
    }

    /**
     * Sequences can only start 1..7 within each number suit.
     * Tile IDs: man 1-9, pin 10-18, sou 19-27, honors 28-34 (per project convention).
     */
    private function canStartSequence(int $tileId): bool
    {
        if ($tileId >= 1  && $tileId <= 7)  return true; // man 1-7
        if ($tileId >= 10 && $tileId <= 16) return true; // pin 1-7
        if ($tileId >= 19 && $tileId <= 25) return true; // sou 1-7
        return false;
    }

    /**
     * Chiitoitsu: exactly 7 distinct pairs.
     *
     * @param array<int,int> $freqMap
     */
    private function isChiitoitsu(array $freqMap): bool
    {
        $pairs = 0;
        foreach ($freqMap as $count) {
            if ($count === 0)       continue;
            if ($count !== 2)       return false;
            $pairs++;
        }
        return $pairs === 7;
    }

    /**
     * Kokushi Musou: all 13 terminals/honors present, with exactly one pair among them.
     * Terminal IDs (man/pin/sou 1 and 9): 1, 9, 10, 18, 19, 27
     * Honor IDs: 28..34
     *
     * @param array<int,int> $freqMap
     */
    private function isKokushi(array $freqMap): bool
    {
        $kokushiIds = [1, 9, 10, 18, 19, 27, 28, 29, 30, 31, 32, 33, 34];

        // Any non-kokushi tile present → fail.
        foreach ($freqMap as $id => $count) {
            if ($count > 0 && !in_array($id, $kokushiIds, true)) {
                return false;
            }
        }

        $pairs = 0;
        foreach ($kokushiIds as $id) {
            $c = $freqMap[$id] ?? 0;
            if ($c === 0) return false;
            if ($c === 2) $pairs++;
            elseif ($c !== 1) return false;
        }
        return $pairs === 1;
    }
}
