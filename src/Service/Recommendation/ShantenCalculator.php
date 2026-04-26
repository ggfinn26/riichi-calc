<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Service\Recommendation;

use Dewa\Mahjong\Entity\Hand;
use Dewa\Mahjong\Repository\TileRepositoryInterface;

/**
 * ShantenCalculator
 * -----------------------------------------------------------------------------
 * Computes the Shanten (向聴) value — the minimum number of tile exchanges
 * required to reach a tenpai (ready) hand.
 *
 * The returned value follows the community convention:
 *   -1 : already winning (agari)
 *    0 : tenpai (one tile away)
 *    1 : iishanten (one exchange away from tenpai)
 *    2 : ryanshanten, etc.
 *
 * Three hand shapes are evaluated and the minimum is returned:
 *   A. Standard (4 sets + 1 pair)
 *   B. Chiitoitsu (7 pairs)
 *   C. Kokushi Musou (13 orphans)
 *
 * Implementation notes:
 *   - The standard shanten algorithm uses a partial-set enumeration
 *     with memoisation; here we use a straightforward backtracking
 *     that tracks (sets, partials, pair) counts, which is fast enough
 *     for interactive calculator use (≤ ~14 tiles).
 */
final class ShantenCalculator
{
    public function __construct(
        private readonly TileRepositoryInterface $tileRepo,
    ) {}

    /**
     * Calculate the minimum shanten value across standard/chiitoitsu/kokushi.
     */
    public function calculate(Hand $hand): int
    {
        $freqMap = $this->buildFreqMap($hand);
        $meldSetCount = count($hand->getMelds());

        $standard   = $this->calculateStandardShanten($freqMap, $meldSetCount);
        $chiitoitsu = $meldSetCount === 0 ? $this->calculateChiitoitsuShanten($freqMap) : PHP_INT_MAX;
        $kokushi    = $meldSetCount === 0 ? $this->calculateKokushiShanten($freqMap)    : PHP_INT_MAX;

        return min($standard, $chiitoitsu, $kokushi);
    }

    /**
     * Build frequency map [tileId => count] for closed hand tiles only.
     *
     * @return array<int,int>
     */
    private function buildFreqMap(Hand $hand): array
    {
        $map = [];
        foreach ($hand->getTiles() as $t) {
            $id = $t->getId();
            $map[$id] = ($map[$id] ?? 0) + 1;
        }
        return $map;
    }

    // ------------------------------------------------------------------
    // Standard (4 sets + 1 pair)
    // ------------------------------------------------------------------

    /**
     * Standard shanten formula:
     *   shanten = 8 - 2 × (complete sets) - max(partials + pair, ...)
     *   where (sets + partials) ≤ 4 and pair counts once.
     *
     * We enumerate all possible decompositions into sets/partials/pairs and
     * keep the maximum “useful” contribution, yielding the minimum shanten.
     *
     * @param array<int,int> $freqMap
     */
    private function calculateStandardShanten(array $freqMap, int $meldSets): int
    {
        $best = PHP_INT_MAX;
        $this->enumerateStandard($freqMap, $meldSets, 0, 0, $best);
        return $best;
    }

    /**
     * @param array<int,int> $freq
     */
    private function enumerateStandard(array $freq, int $sets, int $partials, int $pairs, int &$best): void
    {
        // Locate the next available tile ID.
        $firstId = -1;
        foreach ($freq as $id => $c) {
            if ($c > 0) { $firstId = $id; break; }
        }

        if ($firstId === -1) {
            // Compute shanten estimate for this partition.
            // Cap sets + partials at 4; pair contributes 1 extra up to the cap.
            $blocks = $sets + $partials;
            if ($blocks > 4) {
                $partials -= ($blocks - 4); // excess partials are wasted
                $blocks    = 4;
            }

            $hasPairBonus = ($pairs > 0) ? 1 : 0;

            // Formula: 8 - 2*sets - partials - pairBonus
            $shanten = 8 - (2 * $sets) - $partials - $hasPairBonus;

            // Special case: no pair + 4 blocks → need to downgrade one
            if ($pairs === 0 && $blocks === 4) {
                $shanten = max($shanten, 8 - (2 * $sets) - $partials);
            }

            if ($shanten < $best) $best = $shanten;
            return;
        }

        // Route 0: skip/ignore this first tile (let it dangle as "floating")
        $freq[$firstId]--;
        $this->enumerateStandard($freq, $sets, $partials, $pairs, $best);
        $freq[$firstId]++;

        // Route 1: form a triplet (koutsu)
        if ($freq[$firstId] >= 3) {
            $freq[$firstId] -= 3;
            $this->enumerateStandard($freq, $sets + 1, $partials, $pairs, $best);
            $freq[$firstId] += 3;
        }

        // Route 2: form a pair
        if ($freq[$firstId] >= 2 && $pairs === 0) {
            $freq[$firstId] -= 2;
            $this->enumerateStandard($freq, $sets, $partials, $pairs + 1, $best);
            $freq[$firstId] += 2;

            // Pair as a partial (toitsu-to-koutsu aspiration)
            $freq[$firstId] -= 2;
            $this->enumerateStandard($freq, $sets, $partials + 1, $pairs, $best);
            $freq[$firstId] += 2;
        }

        // Route 3: form a run (shuntsu) — only number suits
        if ($this->canStartSequence($firstId) &&
            ($freq[$firstId + 1] ?? 0) >= 1 &&
            ($freq[$firstId + 2] ?? 0) >= 1)
        {
            $freq[$firstId]--;
            $freq[$firstId + 1]--;
            $freq[$firstId + 2]--;
            $this->enumerateStandard($freq, $sets + 1, $partials, $pairs, $best);
            $freq[$firstId]++;
            $freq[$firstId + 1]++;
            $freq[$firstId + 2]++;
        }

        // Route 4: partial run — ryanmen/penchan (e.g. 4-5)
        if ($this->isNumberSuit($firstId) &&
            ($freq[$firstId + 1] ?? 0) >= 1)
        {
            $freq[$firstId]--;
            $freq[$firstId + 1]--;
            $this->enumerateStandard($freq, $sets, $partials + 1, $pairs, $best);
            $freq[$firstId]++;
            $freq[$firstId + 1]++;
        }

        // Route 5: partial kanchan (e.g. 4-6)
        if ($this->isNumberSuit($firstId) &&
            ($freq[$firstId + 2] ?? 0) >= 1)
        {
            $freq[$firstId]--;
            $freq[$firstId + 2]--;
            $this->enumerateStandard($freq, $sets, $partials + 1, $pairs, $best);
            $freq[$firstId]++;
            $freq[$firstId + 2]++;
        }
    }

    // ------------------------------------------------------------------
    // Chiitoitsu (7 pairs)
    // ------------------------------------------------------------------

    /**
     * Chiitoitsu shanten = 6 - (#pairs) - min(#singles, 7 - #pairs).
     * Simplified (common form): 6 - pairs - max(0, 7 - pairs - kinds).
     *
     * @param array<int,int> $freqMap
     */
    private function calculateChiitoitsuShanten(array $freqMap): int
    {
        $pairs = 0;
        $kinds = 0;
        foreach ($freqMap as $count) {
            if ($count === 0) continue;
            $kinds++;
            if ($count >= 2) $pairs++;
        }

        $shanten = 6 - $pairs;
        if ($kinds < 7) {
            $shanten += (7 - $kinds);
        }
        return $shanten;
    }

    // ------------------------------------------------------------------
    // Kokushi Musou (13 orphans)
    // ------------------------------------------------------------------

    /**
     * Kokushi shanten = 13 - (unique orphans) - (1 if any orphan is paired else 0)
     *
     * Orphan tile IDs: 1, 9, 10, 18, 19, 27, 28, 29, 30, 31, 32, 33, 34.
     *
     * @param array<int,int> $freqMap
     */
    private function calculateKokushiShanten(array $freqMap): int
    {
        $orphans = [1, 9, 10, 18, 19, 27, 28, 29, 30, 31, 32, 33, 34];

        $unique = 0;
        $hasPair = false;
        foreach ($orphans as $id) {
            $c = $freqMap[$id] ?? 0;
            if ($c >= 1) $unique++;
            if ($c >= 2) $hasPair = true;
        }

        return 13 - $unique - ($hasPair ? 1 : 0);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function isNumberSuit(int $tileId): bool
    {
        return ($tileId >= 1 && $tileId <= 9)
            || ($tileId >= 10 && $tileId <= 18)
            || ($tileId >= 19 && $tileId <= 27);
    }

    private function canStartSequence(int $tileId): bool
    {
        if ($tileId >= 1  && $tileId <= 7)  return true;
        if ($tileId >= 10 && $tileId <= 16) return true;
        if ($tileId >= 19 && $tileId <= 25) return true;
        return false;
    }
}
