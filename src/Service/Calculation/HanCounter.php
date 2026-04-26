<?php

namespace Dewa\Mahjong\Service\Calculation;

use Dewa\Mahjong\Entity\Yaku;

/**
 * Aggregator: list yaku + dora count → total han + jumlah yakuman.
 *
 * Service ini TIDAK mendeteksi yaku — itu tugas YakuEvaluator. Caller mengirim
 * Yaku[] + isMenzen + dora count, dan HanCounter merangkum.
 */
final class HanCounter
{
    /**
     * @param Yaku[] $yakuList
     * @return array{han:int, yakumanCount:int}
     */
    public function count(array $yakuList, bool $isMenzen, int $doraCount = 0): array
    {
        $han = 0;
        $yakumanCount = 0;

        foreach ($yakuList as $yaku) {
            if ($yaku->getisYakuman()) {
                $yakumanCount++;
                continue; // Yakuman tidak digabung dengan han biasa.
            }
            $han += $isMenzen ? $yaku->getHanClosed() : $yaku->getHanOpened();
        }

        // Dora hanya menambah ke han biasa, bukan ke yakuman (doc §2).
        if ($yakumanCount === 0) {
            $han += max(0, $doraCount);
        }

        return ['han' => $han, 'yakumanCount' => $yakumanCount];
    }
}
