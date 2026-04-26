<?php

namespace Dewa\Mahjong\Service\Calculation\Fu;

use Dewa\Mahjong\Entity\Tile;
use Dewa\Mahjong\Service\Calculation\ValueObject\Decomposition;
use Dewa\Mahjong\Service\Calculation\ValueObject\ScoringOptions;
use Dewa\Mahjong\Service\Calculation\ValueObject\SetGroup;
use Dewa\Mahjong\Service\Calculation\ValueObject\WaitType;

/**
 * Hitung fu (minipoints) untuk satu Decomposition + konteks menang.
 * Implementasi mengikuti documentation/ScoringCalculation.md §3.
 *
 * Output: ['fu' => int (sudah di-roundUp 10), 'breakdown' => [label => poin]]
 */
final class FuCalculator
{
    /**
     * @param string $roundWind   'east' | 'south' | 'west' | 'north'
     * @param string $seatWind    'east' | 'south' | 'west' | 'north'
     * @param bool   $isPinfu     hasil deteksi YakuEvaluator (pinfu valid utk dekomposisi ini)
     */
    public function calculate(
        Decomposition $dec,
        Tile $winningTile,
        bool $isTsumo,
        bool $isMenzen,
        string $roundWind,
        string $seatWind,
        bool $isPinfu,
        ScoringOptions $opts,
    ): array {
        // --- Cabang khusus shape ---
        if ($dec->shape === Decomposition::SHAPE_CHIITOITSU) {
            return ['fu' => 25, 'breakdown' => ['chiitoitsu_flat' => 25]];
        }
        if ($dec->shape === Decomposition::SHAPE_KOKUSHI) {
            // Kokushi adalah yakuman; fu tidak dipakai untuk skoring, tapi
            // tetap kembalikan 0 agar konsumen tahu.
            return ['fu' => 0, 'breakdown' => ['kokushi_yakuman' => 0]];
        }

        // --- Pinfu overrides ---
        if ($isPinfu) {
            // Pinfu valid hanya saat menzen.
            if ($isTsumo) {
                return ['fu' => 20, 'breakdown' => ['pinfu_tsumo_flat' => 20]];
            }
            // Pinfu ron menzen: 20 base + 10 menzen-ron = 30 flat.
            return ['fu' => 30, 'breakdown' => ['base' => 20, 'menzen_ron' => 10]];
        }

        $breakdown = [];
        $fu = 20;
        $breakdown['base'] = 20;

        // Win condition
        if ($isTsumo) {
            $fu += 2;
            $breakdown['tsumo'] = 2;
        } elseif ($isMenzen) {
            $fu += 10;
            $breakdown['menzen_ron'] = 10;
        }

        // Sets / quads
        foreach ($dec->groups as $idx => $g) {
            if ($g->isPair()) continue;
            if ($g->isChi()) continue;

            // Concealed flag perlu di-adjust jika triplet diselesaikan via ron:
            // diperlakukan OPEN untuk fu, sesuai §3.5 doc.
            $concealed = $g->isConcealed;
            if (!$isTsumo && $idx === $dec->waitGroupIndex && $g->isKoutsu()) {
                $concealed = false;
            }

            $contrib = $this->meldFu($g, $concealed);
            $fu += $contrib;
            $kind = $g->isKantsu() ? 'kantsu' : 'koutsu';
            $tag = ($concealed ? 'concealed_' : 'open_') . $kind . '_' . $idx;
            $breakdown[$tag] = $contrib;
        }

        // Pair fu (yakuhai)
        $pair = $dec->getPair();
        if ($pair !== null) {
            $pairFu = $this->pairFu($pair, $roundWind, $seatWind, $opts);
            if ($pairFu > 0) {
                $fu += $pairFu;
                $breakdown['pair_yakuhai'] = $pairFu;
            }
        }

        // Wait fu
        $waitFu = WaitType::fuFor($dec->waitType);
        if ($waitFu > 0) {
            $fu += $waitFu;
            $breakdown['wait_' . $dec->waitType] = $waitFu;
        }

        // Open pinfu-shape exception: jika hand terbuka dan total tambahan = 0
        // (tidak ada meld fu, tidak ada pair fu, tidak ada wait fu) ⇒ flat 30.
        // Ini bisa terjadi pada open hand dengan semua chi & pair non-yakuhai
        // & wait ryanmen. Lihat doc §3.1.
        if (!$isMenzen && !$isTsumo) {
            // Open ron pinfu-shape — base 20 saja → flat 30.
            $additional = $fu - 20; // win=0, no meld fu, no pair fu, no wait fu
            if ($additional === 0) {
                return ['fu' => 30, 'breakdown' => ['open_pinfu_flat' => 30]];
            }
        }
        if (!$isMenzen && $isTsumo) {
            // Open tsumo pinfu-shape: base 20 + tsumo 2 = 22 → tetap round-up jadi 30.
            // Logic round-up biasa sudah cover ini, tapi doc menulis "open pinfu always 30",
            // yang konsisten dengan rounding standar.
        }

        // Round up ke kelipatan 10.
        $rounded = (int) (ceil($fu / 10) * 10);
        return ['fu' => $rounded, 'breakdown' => $breakdown];
    }

    private function meldFu(SetGroup $g, bool $concealed): int
    {
        $isYaochuu = $g->hasTerminalOrHonor();
        if ($g->isKoutsu()) {
            if ($isYaochuu) return $concealed ? 8 : 4;
            return $concealed ? 4 : 2;
        }
        if ($g->isKantsu()) {
            if ($isYaochuu) return $concealed ? 32 : 16;
            return $concealed ? 16 : 8;
        }
        return 0;
    }

    private function pairFu(SetGroup $pair, string $roundWind, string $seatWind, ScoringOptions $opts): int
    {
        $tile = $pair->representativeTile();
        if (!$tile->isHonor()) return 0;

        $name = strtolower($tile->getName());
        $value = strtolower($tile->getValue());

        // Dragon: white/haku, green/hatsu, red/chun. Match longgar berdasarkan name/value.
        $isDragon = $this->isDragonTile($tile);
        if ($isDragon) return 2;

        // Wind tile: cek apakah seat wind / round wind.
        $windKey = $this->windKeyOf($tile);
        if ($windKey === null) return 0;

        $isSeat  = $windKey === strtolower($seatWind);
        $isRound = $windKey === strtolower($roundWind);

        if ($isSeat && $isRound) {
            return $opts->doubleWindPairFu; // default 4
        }
        if ($isSeat || $isRound) {
            return 2;
        }
        return 0;
    }

    private function isDragonTile(Tile $tile): bool
    {
        if (!$tile->isHonor()) return false;
        $hay = strtolower($tile->getName() . '|' . $tile->getValue());
        foreach (['haku', 'hatsu', 'chun', 'white', 'green', 'red', 'dragon'] as $needle) {
            if (str_contains($hay, $needle)) return true;
        }
        return false;
    }

    private function windKeyOf(Tile $tile): ?string
    {
        if (!$tile->isHonor()) return null;
        $hay = strtolower($tile->getName() . '|' . $tile->getValue());
        foreach (['east','south','west','north','ton','nan','sha','pei'] as $w) {
            if (str_contains($hay, $w)) {
                return match ($w) {
                    'ton' => 'east',
                    'nan' => 'south',
                    'sha' => 'west',
                    'pei' => 'north',
                    default => $w,
                };
            }
        }
        return null;
    }
}
