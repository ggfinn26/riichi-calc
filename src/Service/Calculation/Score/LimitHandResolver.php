<?php

namespace Dewa\Mahjong\Service\Calculation\Score;

use Dewa\Mahjong\Service\Calculation\ValueObject\ScoringOptions;

/**
 * Routing han ≥ 5 ke tier limit hand sesuai Tabel 6.1 RiichiBook1 §6.2.
 *
 * Yakuman murni di-handle terpisah lewat resolveYakuman() — basePoints = 8000
 * dikalikan multiplier (double yakuman, triple, dst).
 */
final class LimitHandResolver
{
    public const NAME_MANGAN     = 'mangan';
    public const NAME_HANEMAN    = 'haneman';
    public const NAME_BAIMAN     = 'baiman';
    public const NAME_SANBAIMAN  = 'sanbaiman';
    public const NAME_YAKUMAN    = 'yakuman';

    /**
     * @return array{basePoints:int, name:string}
     */
    public function resolveByHan(int $han, ScoringOptions $opts): array
    {
        if ($han >= 13) {
            // Kazoe yakuman: 13+ han dengan akumulasi (bukan yakuman murni).
            // Per Tabel 6.1 footnote, EMA revised membatasi ke sanbaiman.
            if ($opts->kazoeYakumanEnabled) {
                return ['basePoints' => 8000, 'name' => self::NAME_YAKUMAN];
            }
            return ['basePoints' => 6000, 'name' => self::NAME_SANBAIMAN];
        }
        if ($han >= 11) return ['basePoints' => 6000, 'name' => self::NAME_SANBAIMAN];
        if ($han >= 8)  return ['basePoints' => 4000, 'name' => self::NAME_BAIMAN];
        if ($han >= 6)  return ['basePoints' => 3000, 'name' => self::NAME_HANEMAN];
        return ['basePoints' => 2000, 'name' => self::NAME_MANGAN];
    }

    /**
     * @return array{basePoints:int, name:string, multiplier:int}
     */
    public function resolveYakuman(int $yakumanCount): array
    {
        // Multi-yakuman: tiap yakuman tambah 1 base mangan blok (8000).
        $mult = max(1, $yakumanCount);
        return [
            'basePoints' => 8000 * $mult,
            'name' => self::NAME_YAKUMAN,
            'multiplier' => $mult,
        ];
    }
}
