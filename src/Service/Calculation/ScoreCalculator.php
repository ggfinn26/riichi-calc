<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Service\Calculation;

use Dewa\Mahjong\Service\Calculation\Score\BasePointsCalculator;
use Dewa\Mahjong\Service\Calculation\ValueObject\Payment;
use Dewa\Mahjong\Service\Calculation\ValueObject\ScoringOptions;

/**
 * ScoreCalculator
 * -----------------------------------------------------------------------------
 * Stateless score calculator. Given total han, total fu, and game situation
 * (dealer / tsumo / ron / honba / riichi sticks), it resolves the final point
 * payments following the Riichi Mahjong scoring formula documented in
 * documentation/ScoringCalculation.md §6.
 *
 * Algorithm summary:
 *   1. basePoints = fu × 2^(2 + han), capped to mangan (2000) for han < 5.
 *      For han ≥ 5, limit hand tiers are applied (mangan, haneman, baiman,
 *      sanbaiman, yakuman).
 *   2. Multiplier selection:
 *        - Dealer ron     : basePoints × 6
 *        - Dealer tsumo   : basePoints × 2 dari tiap non-dealer (total ×6)
 *        - Non-dealer ron : basePoints × 4
 *        - Non-dealer tsumo: basePoints × 2 dari dealer, ×1 dari non-dealer
 *   3. Round UP to nearest 100 for each pay unit.
 *   4. Honba bonus: 300 × honbaCount (on ron the deal-in player pays, on
 *      tsumo each payer pays 100 × honbaCount).
 *   5. Riichi sticks: 1000 × ridingSticks go to the winner as a flat bonus.
 */
final class ScoreCalculator
{
    public function __construct(
        private readonly BasePointsCalculator $basePointsCalculator = new BasePointsCalculator(),
    ) {}

    /**
     * Calculate the final ScoreResult for a winning hand.
     *
     * @param int             $han               total han (sum from YakuEvaluator)
     * @param int             $fu                total fu (rounded UP to nearest 10)
     * @param bool            $isDealer          true if the winner is the oya/dealer
     * @param bool            $isTsumo           true if self-draw, false if ron
     * @param int             $yakumanCount      0 if not yakuman; otherwise number of yakuman stacked
     * @param ScoringOptions  $opts              flags + honba + riichi sticks
     * @param array           $yakuList          yaku objects (untuk dibungkus ke ScoreResult)
     * @param array<string,int> $fuBreakdown     rincian fu label => poin
     *
     * @return ScoreResult
     */
    public function calculate(
        int $han,
        int $fu,
        bool $isDealer,
        bool $isTsumo,
        int $yakumanCount = 0,
        ?ScoringOptions $opts = null,
        array $yakuList = [],
        array $fuBreakdown = []
    ): ScoreResult {
        $opts = $opts ?? new ScoringOptions();

        // 1. Base points.
        $baseResult  = $this->basePointsCalculator->calculate($han, $fu, $yakumanCount, $opts);
        $basePoints  = $baseResult['basePoints'];
        $limitName   = $baseResult['limitName'];
        $yMultiplier = $baseResult['yakumanMultiplier'];

        // 2. Build Payment distribution.
        $payment = $this->buildPayment($basePoints, $isDealer, $isTsumo, $opts->honbaCount);

        // 3. Bonuses.
        $honbaBonus       = 300 * $opts->honbaCount;                // total honba points winner receives
        $riichiStickBonus = 1000 * $opts->riichiSticksOnTable;     // riichi pool claimed

        // 4. Final total (sum of all payments already includes honba distributed per-payer
        //    inside buildPayment; add only the riichi stick pool here since that's a flat pool).
        $finalTotal = $payment->total + $riichiStickBonus;

        return new ScoreResult(
            han: $han,
            fu: $fu,
            fuBreakdown: $fuBreakdown,
            yakuList: $yakuList,
            basePoints: $basePoints,
            limitName: $limitName,
            yakumanMultiplier: $yMultiplier,
            payment: $payment,
            honbaBonus: $honbaBonus,
            riichiStickBonus: $riichiStickBonus,
            finalTotal: $finalTotal,
            decomposition: null,
        );
    }

    /**
     * Distribute base points into concrete per-player payments including honba.
     */
    private function buildPayment(int $basePoints, bool $isDealer, bool $isTsumo, int $honbaCount): Payment
    {
        // Per the standard formula:
        // - Dealer ron        : 6 × basePoints, paid solely by the deal-in player.
        // - Dealer tsumo      : 2 × basePoints each from 3 non-dealers.
        // - Non-dealer ron    : 4 × basePoints, paid solely by the deal-in player.
        // - Non-dealer tsumo  : 2 × basePoints from dealer; 1 × basePoints from each non-dealer.
        //
        // Payments are rounded UP to the nearest 100 per pay unit.
        // Honba adds 300 total on ron and 100 per payer on tsumo.

        if (!$isTsumo) {
            // Ron
            $multiplier = $isDealer ? 6 : 4;
            $amount     = $this->roundUp100($basePoints * $multiplier);
            $amount    += 300 * $honbaCount; // all honba paid by deal-in
            return Payment::ron($amount);
        }

        // Tsumo
        if ($isDealer) {
            $perNonDealer  = $this->roundUp100($basePoints * 2);
            $perNonDealer += 100 * $honbaCount;
            return Payment::tsumoDealer($perNonDealer);
        }

        $fromDealer     = $this->roundUp100($basePoints * 2);
        $fromNonDealer  = $this->roundUp100($basePoints * 1);
        $fromDealer    += 100 * $honbaCount;
        $fromNonDealer += 100 * $honbaCount;

        return Payment::tsumoNonDealer($fromDealer, $fromNonDealer);
    }

    /**
     * Round a positive number UP to the nearest 100 (standard mahjong rounding).
     */
    private function roundUp100(int $n): int
    {
        return (int) (ceil($n / 100) * 100);
    }
}
