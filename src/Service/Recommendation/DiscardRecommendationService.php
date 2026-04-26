<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Service\Recommendation;

use Dewa\Mahjong\Entity\GameContext;
use Dewa\Mahjong\Entity\Hand;
use Dewa\Mahjong\Entity\Tile;
use Dewa\Mahjong\Service\Calculation\WaitCalculator;

/**
 * DiscardRecommendationService (Nani Kiru?)
 * -----------------------------------------------------------------------------
 * Top-level orchestrator of the recommendation pipeline. For every tile in the
 * player's closed hand it simulates a discard and computes three metrics:
 *
 *   - Speed   : improvement in shanten, weighted by realistic ukeire count
 *               from {@see VisibleTileTrackerService}.
 *   - Defense : average safety score against all opponents currently
 *               threatening (riichi or assumed tenpai), 0..100 (higher = safer).
 *   - Value   : expected basePoints from {@see ExpectedValueCalculator}.
 *
 * The final ranking is a normalised weighted combination (default weights
 * 0.5 speed / 0.3 defense / 0.2 value) but callers can override the weights.
 */
final class DiscardRecommendationService
{
    public function __construct(
        private readonly ShantenCalculator $shantenCalc,
        private readonly VisibleTileTrackerService $trackerService,
        private readonly DefenseEvaluatorService $defenseEval,
        private readonly ExpectedValueCalculator $evCalc,
        private readonly WaitCalculator $waitCalculator,
    ) {}

    /**
     * @param int[] $threateningUserIds list of opponent user ids to evaluate
     *                                   defense against (empty = no threats).
     * @param array{speed?:float,defense?:float,value?:float} $weights
     *
     * @return array<int, array{
     *     tileId: int,
     *     tileName: string,
     *     shantenAfter: int,
     *     ukeireCount: int,
     *     speedScore: float,
     *     defenseScore: float,
     *     valueScore: float,
     *     totalScore: float
     * }>  ordered best → worst
     */
    public function getRecommendations(
        Hand $hand,
        GameContext $context,
        array $threateningUserIds = [],
        array $weights = []
    ): array {
        $w = array_merge(['speed' => 0.5, 'defense' => 0.3, 'value' => 0.2], $weights);

        $tilesInHand = $hand->getTiles();
        if ($tilesInHand === []) return [];

        // Deduplicate: same tile kind discarded twice yields the same simulation.
        $uniqueByTileId = [];
        foreach ($tilesInHand as $t) $uniqueByTileId[$t->getId()] = $t;

        // Precompute remaining tiles map (used repeatedly).
        $remainingMap = $this->trackerService->getRemainingTilesProbabilities($context);

        $recommendations = [];
        foreach ($uniqueByTileId as $candidate) {
            $sim = $this->simulateDiscard($hand, $candidate);
            if ($sim === null) continue;

            $simulatedHand = $sim['hand'];

            // 1. Speed metric
            $shantenAfter = $this->shantenCalc->calculate($simulatedHand);
            $waits        = $this->waitCalculator->calculateWaits($simulatedHand);
            $ukeireCount  = $this->sumUkeire($waits, $remainingMap, $simulatedHand);
            $speedScore   = $this->speedScore($shantenAfter, $ukeireCount);

            // 2. Defense metric (average across all threats; 100 = safe).
            $defenseScore = $this->defenseScore($candidate, $context, $threateningUserIds);

            // 3. Value metric (normalise basePoints to 0..100 roughly).
            $ukeireWeights = [];
            foreach ($waits as $wt) {
                $ukeireWeights[$wt->getId()] = $remainingMap[$wt->getId()] ?? 0;
            }
            $ev = $this->evCalc->calculateEV($simulatedHand, $waits, $context, true, $simulatedHand->isRiichiDeclared(), $ukeireWeights);
            $valueScore = $this->normalizeValue($ev);

            $totalScore = ($w['speed']   * $speedScore)
                        + ($w['defense'] * $defenseScore)
                        + ($w['value']   * $valueScore);

            $recommendations[] = [
                'tileId'       => $candidate->getId(),
                'tileName'     => $candidate->getName(),
                'shantenAfter' => $shantenAfter,
                'ukeireCount'  => $ukeireCount,
                'speedScore'   => $speedScore,
                'defenseScore' => $defenseScore,
                'valueScore'   => $valueScore,
                'totalScore'   => $totalScore,
            ];
        }

        // Sort by totalScore DESC.
        usort($recommendations, fn($a, $b) => $b['totalScore'] <=> $a['totalScore']);
        return $recommendations;
    }

    // ------------------------------------------------------------------
    // Simulation helpers
    // ------------------------------------------------------------------

    /**
     * Produce a shallow copy of the hand with one tile removed.
     * Returns null if the candidate is not present.
     *
     * @return array{hand:Hand}|null
     */
    private function simulateDiscard(Hand $hand, Tile $candidate): ?array
    {
        $found = false;
        $newTiles = [];
        foreach ($hand->getTiles() as $t) {
            if (!$found && $t->getId() === $candidate->getId()) {
                $found = true;
                continue;
            }
            $newTiles[] = $t;
        }
        if (!$found) return null;

        // Build a new Hand with the reduced tile set. Copy melds too.
        $clone = new Hand(
            $hand->getId(),
            $hand->getGameContextId(),
            $hand->getUserId(),
            $hand->isDealer()
        );
        foreach ($newTiles as $t) $clone->addTile($t);
        foreach ($hand->getMelds() as $m) $clone->addMeld($m);

        // Restore riichi status for downstream EV calculation.
        if ($hand->isRiichiDeclared()) {
            $clone->setRiichiDeclared(true, $hand->getRiichiDiscardId());
        }

        return ['hand' => $clone];
    }

    /**
     * Sum realistic ukeire — for each waiting tile kind, add the number of
     * copies not yet visible on the table (minus copies we're holding).
     *
     * @param Tile[]          $waits
     * @param array<int,int>  $remainingMap
     */
    private function sumUkeire(array $waits, array $remainingMap, Hand $hand): int
    {
        $ownCounts = [];
        foreach ($hand->getTiles() as $t) {
            $ownCounts[$t->getId()] = ($ownCounts[$t->getId()] ?? 0) + 1;
        }

        $total = 0;
        foreach ($waits as $w) {
            $rem = $remainingMap[$w->getId()] ?? 0;
            $own = $ownCounts[$w->getId()] ?? 0;
            $total += max(0, $rem - $own);
        }
        return $total;
    }

    // ------------------------------------------------------------------
    // Scoring helpers (normalised 0..100)
    // ------------------------------------------------------------------

    private function speedScore(int $shanten, int $ukeire): float
    {
        // Lower shanten is far better. Tenpai baseline = 80, iishanten = 60, etc.
        $shantenComponent = match (true) {
            $shanten <= 0 => 80.0,
            $shanten === 1 => 60.0,
            $shanten === 2 => 40.0,
            $shanten === 3 => 25.0,
            default       => 10.0,
        };
        // Add up to +20 based on ukeire count (saturates at 20 tiles).
        $ukeireComponent = min(20.0, $ukeire);
        return min(100.0, $shantenComponent + $ukeireComponent);
    }

    /**
     * @param int[] $threateningUserIds
     */
    private function defenseScore(Tile $candidate, GameContext $context, array $threateningUserIds): float
    {
        if ($threateningUserIds === []) {
            return 100.0; // no threat → fully safe
        }

        $dangerTotals = 0.0;
        foreach ($threateningUserIds as $uid) {
            $dangerTotals += $this->defenseEval->evaluateDangerLevel($candidate, $context, $uid);
        }
        $avgDanger = $dangerTotals / count($threateningUserIds);
        return max(0.0, 100.0 - $avgDanger);
    }

    private function normalizeValue(float $basePoints): float
    {
        // basePoints typical: 0 … 8000 (yakuman). Normalise logarithmically to 0..100.
        if ($basePoints <= 0.0) return 0.0;
        // log(8001) ≈ 8.99 → map to 100.
        $score = log($basePoints + 1) / log(8001) * 100.0;
        return max(0.0, min(100.0, $score));
    }
}
