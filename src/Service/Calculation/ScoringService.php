<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Service\Calculation;

use Dewa\Mahjong\Service\Calculation\Fu\FuCalculator;
use Dewa\Mahjong\Service\Calculation\Fu\HandDecomposer;
use Dewa\Mahjong\Service\Calculation\ValueObject\Decomposition;
use Dewa\Mahjong\Service\Calculation\ValueObject\Payment;
use Dewa\Mahjong\Service\Calculation\ValueObject\ScoringOptions;

/**
 * Orchestrator scoring level-tinggi.
 *
 * Tanggung jawab:
 *   1. Validasi input (tile count + min 1 yaku).
 *   2. Decompose hand → semua dekomposisi sah.
 *   3. Untuk tiap dekomposisi → hitung fu (FuCalculator) → hitung basePoints lewat
 *      ScoreCalculator. Pilih dekomposisi dengan hasil tertinggi.
 *   4. Apply pao redistribution (jika applicable) ke Payment hasil.
 *
 * ScoreCalculator dijaga tetap pure-math (han/fu → score). Service ini yang
 * mengaitkan dengan domain entity (Hand, Tile, Yaku).
 */
final class ScoringService
{
    public function __construct(
        private readonly HandDecomposer  $decomposer = new HandDecomposer(),
        private readonly FuCalculator    $fuCalc     = new FuCalculator(),
        private readonly HanCounter      $hanCounter = new HanCounter(),
        private readonly ScoreCalculator $scoreCalc  = new ScoreCalculator(),
    ) {}

    public function calculate(WinningHandInput $input, ?ScoringOptions $opts = null): ScoreResult
    {
        $opts = $opts ?? new ScoringOptions();
        $hand = $input->hand;

        if (!$hand->isValidTileCount()) {
            throw new \InvalidArgumentException('Tile count tangan tidak valid untuk scoring.');
        }
        if (count($input->yakuList) === 0) {
            throw new \InvalidArgumentException('Tidak ada yaku — hand tidak bisa di-score.');
        }

        $isMenzen  = $hand->isMenzen();
        $isDealer  = $hand->isDealer();

        $hanInfo      = $this->hanCounter->count($input->yakuList, $isMenzen, $input->doraCount);
        $han          = $hanInfo['han'];
        $yakumanCount = $hanInfo['yakumanCount'];

        $decompositions = $this->decomposer->decompose($hand, $input->winningTile, $input->isTsumo);

        // --- Yakuman: bypass perhitungan fu, panggil ScoreCalculator dengan yakumanCount ---
        if ($yakumanCount > 0) {
            $result = $this->scoreCalc->calculate(
                han: 0,
                fu: 0,
                isDealer: $isDealer,
                isTsumo: $input->isTsumo,
                yakumanCount: $yakumanCount,
                opts: $opts,
                yakuList: $input->yakuList,
                fuBreakdown: ['yakuman' => 0],
            );
            $result = $this->withDecomposition($result, $decompositions[0] ?? null);
            return $this->maybeApplyPao($result, $input, $opts, true);
        }

        // --- Limit hand non-yakuman (han ≥ 5) ---
        if ($han >= 5) {
            $result = $this->scoreCalc->calculate(
                han: $han,
                fu: 0,
                isDealer: $isDealer,
                isTsumo: $input->isTsumo,
                yakumanCount: 0,
                opts: $opts,
                yakuList: $input->yakuList,
                fuBreakdown: [],
            );
            return $this->withDecomposition($result, $decompositions[0] ?? null);
        }

        // --- Hand non-limit: cari dekomposisi terbaik ---
        if (count($decompositions) === 0) {
            throw new \InvalidArgumentException('Hand tidak bisa di-decompose ke pola valid.');
        }

        $best = null;
        foreach ($decompositions as $dec) {
            $pinfuValid = $this->isPinfuValidFor($dec, $input, $isMenzen);
            $fuInfo = $this->fuCalc->calculate(
                $dec,
                $input->winningTile,
                $input->isTsumo,
                $isMenzen,
                $input->roundWind,
                $input->seatWind,
                $pinfuValid,
                $opts,
            );
            $candidate = $this->scoreCalc->calculate(
                han: $han,
                fu: $fuInfo['fu'],
                isDealer: $isDealer,
                isTsumo: $input->isTsumo,
                yakumanCount: 0,
                opts: $opts,
                yakuList: $input->yakuList,
                fuBreakdown: $fuInfo['breakdown'],
            );
            $candidate = $this->withDecomposition($candidate, $dec);

            if ($best === null
                || $candidate->basePoints > $best->basePoints
                || ($candidate->basePoints === $best->basePoints && $candidate->fu > $best->fu)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private function isPinfuValidFor(Decomposition $dec, WinningHandInput $input, bool $isMenzen): bool
    {
        if (!$input->isPinfu) return false;
        if (!$isMenzen) return false;
        if ($dec->shape !== Decomposition::SHAPE_STANDARD) return false;
        if ($dec->waitType !== 'ryanmen') return false;
        foreach ($dec->getSets() as $set) {
            if (!$set->isChi()) return false;
        }
        return true;
    }

    private function withDecomposition(ScoreResult $r, ?Decomposition $dec): ScoreResult
    {
        if ($dec === null) return $r;
        return new ScoreResult(
            han: $r->han,
            fu: $r->fu,
            fuBreakdown: $r->fuBreakdown,
            yakuList: $r->yakuList,
            basePoints: $r->basePoints,
            limitName: $r->limitName,
            yakumanMultiplier: $r->yakumanMultiplier,
            payment: $r->payment,
            honbaBonus: $r->honbaBonus,
            riichiStickBonus: $r->riichiStickBonus,
            finalTotal: $r->finalTotal,
            decomposition: $dec,
        );
    }

    /**
     * Pao (sekinin barai) redistribusi.
     *  - Tsumo: pao player bayar penuh (sebesar total tsumo).
     *  - Ron  : pao player & deal-in split 50-50.
     */
    private function maybeApplyPao(ScoreResult $r, WinningHandInput $input, ScoringOptions $opts, bool $isYakuman): ScoreResult
    {
        if (!$isYakuman) return $r;
        if ($opts->paoPlayerId === null || $opts->paoYakumanType === null) return $r;

        $paoEligible = ['daisangen', 'daisuushii', 'suukantsu'];
        if (!in_array($opts->paoYakumanType, $paoEligible, true)) return $r;

        // Confirm yaku list contains the matching pao yakuman.
        $matched = false;
        foreach ($input->yakuList as $y) {
            $name = strtolower($y->getNameEng() . '|' . $y->getNameJp());
            if (str_contains($name, $opts->paoYakumanType)) { $matched = true; break; }
        }
        if (!$matched) return $r;

        $totalSansSticks = $r->payment->total; // payment.total sudah include honba
        if ($input->isTsumo) {
            // Pao bayar penuh.
            $newPayment = new Payment(
                fromDealInPlayer: $totalSansSticks,
                fromDealer: 0,
                fromNonDealer: 0,
                total: $totalSansSticks,
            );
        } else {
            // Ron: split 50-50.
            $half = (int) (ceil(intdiv($totalSansSticks, 2) / 100) * 100);
            $newPayment = new Payment(
                fromDealInPlayer: $half,
                fromDealer: $half, // slot pao share
                fromNonDealer: 0,
                total: $half * 2,
            );
        }

        return new ScoreResult(
            han: $r->han,
            fu: $r->fu,
            fuBreakdown: $r->fuBreakdown,
            yakuList: $r->yakuList,
            basePoints: $r->basePoints,
            limitName: $r->limitName,
            yakumanMultiplier: $r->yakumanMultiplier,
            payment: $newPayment,
            honbaBonus: $r->honbaBonus,
            riichiStickBonus: $r->riichiStickBonus,
            finalTotal: $newPayment->total + $r->riichiStickBonus,
            decomposition: $r->decomposition,
        );
    }
}
