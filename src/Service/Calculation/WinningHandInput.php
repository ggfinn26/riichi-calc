<?php

namespace Dewa\Mahjong\Service\Calculation;

use Dewa\Mahjong\Entity\Hand;
use Dewa\Mahjong\Entity\Tile;
use Dewa\Mahjong\Entity\Yaku;

/**
 * Bundling parameter input untuk ScoreCalculator::calculate().
 *
 * yakuList datang dari YakuEvaluator (eksternal). Service tidak mendeteksi
 * yaku — ia hanya menghitung skor presisi.
 */
final class WinningHandInput
{
    /**
     * @param Yaku[] $yakuList         hasil deteksi YakuEvaluator
     * @param bool   $isPinfu          true jika dekomposisi pemenang sah sebagai pinfu
     */
    public function __construct(
        public readonly Hand $hand,
        public readonly Tile $winningTile,
        public readonly bool $isTsumo,
        public readonly string $roundWind,   // 'east' / 'south' / 'west' / 'north'
        public readonly string $seatWind,
        public readonly array $yakuList,
        public readonly int   $doraCount = 0,
        public readonly bool  $isPinfu = false,
    ) {}
}
