<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Service\Calculation;

use Dewa\Mahjong\Service\Calculation\ValueObject\ScoringOptions;

/**
 * Nagashi mangan: kondisi exhaustive draw di mana semua discard pemain
 * adalah terminal/honor dan tidak ada yang dicall lawan. Skor flat mangan,
 * dibayar seolah-olah tsumo.
 *
 * Service ini DELIBERATELY terpisah dari ScoreCalculator (lihat doc §10.3):
 * tidak ada yaku, tidak ada fu, hanya basePoints = 2000 dengan rule tsumo.
 */
final class NagashiManganResolver
{
    public function __construct(
        private readonly ScoreCalculator $scoreCalc = new ScoreCalculator(),
    ) {}

    /**
     * Apakah pola discard milik pemain memenuhi syarat nagashi mangan?
     *
     * @param array $playerDiscards array of Tile (semua discard yang masih di pile, bukan dicall)
     */
    public function isEligible(array $playerDiscards, bool $hasAnyDiscardCalled): bool
    {
        if ($hasAnyDiscardCalled) return false;
        if (count($playerDiscards) === 0) return false;
        foreach ($playerDiscards as $t) {
            if (!$t->isHonor() && !$t->isTerminal()) return false;
        }
        return true;
    }

    /**
     * Resolve skor untuk pemain yang mengklaim nagashi mangan.
     */
    public function resolve(bool $isDealer, ?ScoringOptions $opts = null): ScoreResult
    {
        $opts = $opts ?? new ScoringOptions();
        // Mangan flat = basePoints 2000, dibayar seolah-olah tsumo.
        // Reuse ScoreCalculator agar formula bayar konsisten.
        return $this->scoreCalc->calculate(
            han: 5,                  // dummy ≥ 5 supaya basePoints di-cap mangan
            fu: 0,
            isDealer: $isDealer,
            isTsumo: true,
            yakumanCount: 0,
            opts: $opts,
            yakuList: [],
            fuBreakdown: ['nagashi_mangan_flat' => 2000],
        );
    }
}
