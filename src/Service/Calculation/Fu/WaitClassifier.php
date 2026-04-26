<?php

namespace Dewa\Mahjong\Service\Calculation\Fu;

use Dewa\Mahjong\Entity\Tile;
use Dewa\Mahjong\Service\Calculation\ValueObject\SetGroup;
use Dewa\Mahjong\Service\Calculation\ValueObject\WaitType;

/**
 * Klasifikasi wait pada SetGroup yang diselesaikan winning tile.
 *
 *  - Pair  + tile pemenang = pair complete  → tanki  (single wait, +2 fu)
 *  - Koutsu (triplet) yang diselesaikan tile pemenang dari shanpon → shanpon (+0)
 *  - Chi (sequence) → ryanmen / kanchan / penchan tergantung posisi winning tile
 */
final class WaitClassifier
{
    public function classify(SetGroup $waitGroup, Tile $winningTile): string
    {
        if ($waitGroup->isPair()) {
            return WaitType::TANKI;
        }
        if ($waitGroup->isKoutsu() || $waitGroup->isKantsu()) {
            return WaitType::SHANPON;
        }
        // Chi: tentukan posisi winning tile dalam sequence.
        // Tile sequence punya value '1'..'9'.
        $values = array_map(fn(Tile $t) => (int)$t->getValue(), $waitGroup->tiles);
        sort($values);
        $win = (int)$winningTile->getValue();

        // Kanchan: winning tile di tengah, mis. 3-(4)-5.
        if ($win === $values[1] && $win !== $values[0] && $win !== $values[2]) {
            return WaitType::KANCHAN;
        }
        // Penchan: 1-2 menunggu 3, atau 8-9 menunggu 7.
        if (($values[0] === 1 && $values[1] === 2 && $values[2] === 3 && $win === 3) ||
            ($values[0] === 7 && $values[1] === 8 && $values[2] === 9 && $win === 7)) {
            return WaitType::PENCHAN;
        }
        return WaitType::RYANMEN;
    }
}
