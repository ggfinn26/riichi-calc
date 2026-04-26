<?php

namespace Dewa\Mahjong\Service\Calculation\ValueObject;

/**
 * Flag konfigurasi scoring. Default mengikuti Tenhou / EMA seperti dijelaskan
 * di documentation/ScoringCalculation.md §10.
 */
final class ScoringOptions
{
    public function __construct(
        public readonly bool $kazoeYakumanEnabled = true,
        public readonly bool $kiriageManganEnabled = false,
        public readonly int  $doubleWindPairFu = 4,

        public readonly int  $honbaCount = 0,
        public readonly int  $riichiSticksOnTable = 0,

        // Untuk pao (sekinin barai) — caller yang tahu siapa pao player.
        public readonly ?int    $paoPlayerId = null,
        public readonly ?string $paoYakumanType = null, // 'daisangen' | 'daisuushii' | 'suukantsu'
    ) {}
}
