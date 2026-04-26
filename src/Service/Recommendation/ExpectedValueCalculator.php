<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Service\Recommendation;

use Dewa\Mahjong\Entity\GameContext;
use Dewa\Mahjong\Entity\Hand;
use Dewa\Mahjong\Entity\Tile;
use Dewa\Mahjong\Service\Calculation\ScoreCalculator;
use Dewa\Mahjong\Service\Calculation\ValueObject\ScoringOptions;
use Dewa\Mahjong\Service\Calculation\YakuEvaluator;

/**
 * ExpectedValueCalculator
 * -----------------------------------------------------------------------------
 * Estimates the expected score value (EV) of a hand given a set of possible
 * winning (ukeire) tiles. For every ukeire tile the YakuEvaluator is run in
 * a "what-if" simulation to determine the han produced; a coarse fu estimate
 * combined with ScoreCalculator yields a winning basePoints number; those are
 * averaged across the ukeire set (optionally weighted by realistic remaining
 * counts provided by the caller).
 *
 * This service deliberately uses an approximation for fu (default: 30) so
 * that UI-level recommendation stays lightweight. A full fu simulation lives
 * in the scoring pipeline used after a real win.
 */
final class ExpectedValueCalculator
{
    /** Default fu used for EV estimation when exact fu is not computed. */
    private const DEFAULT_FU_ESTIMATE = 30;

    public function __construct(
        private readonly YakuEvaluator $yakuEvaluator,
        private readonly ScoreCalculator $scoreCalculator,
    ) {}

    /**
     * @param Tile[]              $ukeire   candidate winning tiles
     * @param array<int,int>|null $weights  optional map [tileId => remainingCount].
     *                                      If provided, tiles are weighted by how
     *                                      many copies are still realistically drawable.
     *
     * @return float expected basePoints average across ukeire
     */
    public function calculateEV(Hand $hand, array $ukeire, GameContext $context, bool $isTsumoAssumed = true, bool $isRiichi = false, ?array $weights = null): float
    {
        if ($ukeire === []) {
            return 0.0;
        }

        $totalWeightedValue = 0.0;
        $totalWeight        = 0.0;

        foreach ($ukeire as $winningTile) {
            // Weight for this tile (defaults to 1.0).
            $w = 1.0;
            if ($weights !== null) {
                $w = (float) ($weights[$winningTile->getId()] ?? 0);
                if ($w <= 0.0) continue;
            }

            // Simulate yaku.
            $result = $this->yakuEvaluator->evaluate(
                $hand,
                $winningTile,
                $context,
                $isTsumoAssumed,
                $isRiichi
            );

            $han = (int) ($result['total_han'] ?? 0);
            if ($han <= 0) {
                // No yaku means the hand cannot legally win with this tile → skip.
                continue;
            }

            // Approximate fu and compute basePoints.
            $score = $this->scoreCalculator->calculate(
                han: $han,
                fu: self::DEFAULT_FU_ESTIMATE,
                isDealer: $hand->isDealer(),
                isTsumo: $isTsumoAssumed,
                yakumanCount: $this->countYakuman($result['yakus'] ?? []),
                opts: new ScoringOptions(honbaCount: $context->getHonba(), riichiSticksOnTable: $context->getRiichiSticks()),
                yakuList: $result['yakus'] ?? [],
            );

            $totalWeightedValue += $score->finalTotal * $w;
            $totalWeight        += $w;
        }

        return $totalWeight > 0.0
            ? $totalWeightedValue / $totalWeight
            : 0.0;
    }

    /**
     * Count yakuman entries within a yaku list. Supports both standard Yaku and
     * CustomYaku entities (both expose getIsYakuman-like accessors).
     *
     * @param array $yakus
     */
    private function countYakuman(array $yakus): int
    {
        $count = 0;
        foreach ($yakus as $y) {
            if (method_exists($y, 'getisYakuman') && $y->getisYakuman()) {
                $count++;
            } elseif (method_exists($y, 'isYakuman') && $y->isYakuman()) {
                $count++;
            }
        }
        return $count;
    }
}
