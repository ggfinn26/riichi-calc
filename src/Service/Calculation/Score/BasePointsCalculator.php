<?php

namespace Dewa\Mahjong\Service\Calculation\Score;

use Dewa\Mahjong\Service\Calculation\ValueObject\ScoringOptions;

/**
 * Hitung basePoints sesuai §6.4: basePoints = fu × 2^(2+han), di-cap mangan (2000)
 * atau ke tier limit untuk han ≥ 5. Lihat documentation/ScoringCalculation.md §4.
 */
final class BasePointsCalculator
{
    public function __construct(
        private readonly LimitHandResolver $limitResolver = new LimitHandResolver(),
    ) {}

    /**
     * @return array{basePoints:int, limitName:?string, yakumanMultiplier:int}
     */
    public function calculate(int $han, int $fu, int $yakumanCount, ScoringOptions $opts): array
    {
        // Yakuman murni dulu — bypass perhitungan han biasa.
        if ($yakumanCount > 0) {
            $r = $this->limitResolver->resolveYakuman($yakumanCount);
            return [
                'basePoints' => $r['basePoints'],
                'limitName' => $r['name'],
                'yakumanMultiplier' => $r['multiplier'],
            ];
        }

        // Limit hand non-yakuman (han ≥ 5) — fu tidak berpengaruh.
        if ($han >= 5) {
            $r = $this->limitResolver->resolveByHan($han, $opts);
            return [
                'basePoints' => $r['basePoints'],
                'limitName' => $r['name'],
                'yakumanMultiplier' => 0,
            ];
        }

        // Hand biasa: rumus fu × 2^(2+han).
        $base = $fu * (2 ** (2 + $han));

        // Kiriage mangan (opsional) — 4han30, 3han60, 3han70 di-promote.
        if ($opts->kiriageManganEnabled) {
            if (($han === 4 && $fu === 30) || ($han === 3 && $fu === 60) || ($han === 3 && $fu === 70)) {
                return ['basePoints' => 2000, 'limitName' => LimitHandResolver::NAME_MANGAN, 'yakumanMultiplier' => 0];
            }
        }

        // Cap mangan: basePoints tidak boleh > 2000 untuk han < 5 (terjadi mis. pada
        // 4 han 40 fu = 2560 → cap 2000).
        if ($base >= 2000) {
            return ['basePoints' => 2000, 'limitName' => LimitHandResolver::NAME_MANGAN, 'yakumanMultiplier' => 0];
        }

        return ['basePoints' => $base, 'limitName' => null, 'yakumanMultiplier' => 0];
    }
}
