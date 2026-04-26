<?php

namespace Dewa\Mahjong\Service\Calculation\Fu;

use Dewa\Mahjong\Entity\Hand;
use Dewa\Mahjong\Entity\Meld;
use Dewa\Mahjong\Entity\Tile;
use Dewa\Mahjong\Service\Calculation\ValueObject\Decomposition;
use Dewa\Mahjong\Service\Calculation\ValueObject\SetGroup;

/**
 * Mendekomposisi tangan + winning tile menjadi semua valid decomposition.
 *
 * Output:
 *  - Standard: semua kombinasi (4 set + 1 pair) yang sah.
 *  - Chiitoitsu: jika 7 pair unik (hanya bila menzen, tanpa meld).
 *  - Kokushi: jika 13 yaochuu unik + 1 duplikat (hanya bila menzen, tanpa meld).
 *
 * Identitas tile berdasarkan (type, value) — bukan id — supaya copy ganda
 * (mis. dua kali 5m) terhitung benar untuk koutsu/pair.
 */
final class HandDecomposer
{
    /**
     * @return Decomposition[]
     */
    public function decompose(Hand $hand, Tile $winningTile, bool $isTsumo): array
    {
        $melds = $hand->getMelds();
        $concealedTiles = $hand->getTiles();

        // Kumpulkan tile yang masuk ke "free pool" (concealed + winning).
        $pool = $concealedTiles;
        $pool[] = $winningTile;

        $decompositions = [];

        // Cabang chiitoitsu/kokushi hanya jika tidak ada meld terbuka sama sekali.
        // Ankan TIDAK valid untuk chiitoitsu/kokushi.
        if (count($melds) === 0) {
            $chiitoitsu = $this->tryChiitoitsu($pool, $winningTile);
            if ($chiitoitsu !== null) $decompositions[] = $chiitoitsu;

            $kokushi = $this->tryKokushi($pool, $winningTile);
            if ($kokushi !== null) $decompositions[] = $kokushi;
        }

        // Standard: open melds = group tetap, sisanya didekomposisi.
        $fixedGroups = array_map(fn(Meld $m) => $this->meldToGroup($m), $melds);
        $needSets = 4 - count($fixedGroups);
        if ($needSets >= 0) {
            $standardDecs = $this->decomposeStandard($pool, $fixedGroups, $needSets, $winningTile);
            foreach ($standardDecs as $d) $decompositions[] = $d;
        }

        return $decompositions;
    }

    // -----------------------------------------------------------------
    // Chiitoitsu
    // -----------------------------------------------------------------
    private function tryChiitoitsu(array $pool, Tile $winningTile): ?Decomposition
    {
        if (count($pool) !== 14) return null;
        $counts = $this->countByKey($pool);
        if (count($counts) !== 7) return null;
        foreach ($counts as $c) {
            if ($c !== 2) return null;
        }
        // Bangun 7 pair groups.
        $groups = [];
        $waitIdx = 0;
        $byKey = $this->groupByKey($pool);
        $winKey = $this->key($winningTile);
        $i = 0;
        foreach ($byKey as $key => $tiles) {
            $g = new SetGroup(SetGroup::KIND_PAIR, $tiles, isConcealed: true);
            $groups[] = $g;
            if ($key === $winKey) $waitIdx = $i;
            $i++;
        }
        return new Decomposition(
            shape: Decomposition::SHAPE_CHIITOITSU,
            groups: $groups,
            waitType: 'tanki',
            waitGroupIndex: $waitIdx,
        );
    }

    // -----------------------------------------------------------------
    // Kokushi
    // -----------------------------------------------------------------
    private function tryKokushi(array $pool, Tile $winningTile): ?Decomposition
    {
        if (count($pool) !== 14) return null;
        // Semua tile harus terminal/honor.
        foreach ($pool as $t) {
            if (!$t->isTerminal() && !$t->isHonor()) return null;
        }
        $counts = $this->countByKey($pool);
        // Harus 13 jenis berbeda dengan 1 duplikat.
        if (count($counts) !== 13) return null;
        $hasPair = false;
        foreach ($counts as $c) {
            if ($c === 2) $hasPair = true;
            elseif ($c !== 1) return null;
        }
        if (!$hasPair) return null;
        // Representasi grup: simpan semua tile sebagai 1 group "kokushi".
        $g = new SetGroup(SetGroup::KIND_PAIR, $pool, isConcealed: true);
        return new Decomposition(
            shape: Decomposition::SHAPE_KOKUSHI,
            groups: [$g],
            waitType: 'tanki',
            waitGroupIndex: 0,
        );
    }

    // -----------------------------------------------------------------
    // Standard 4-set + 1-pair
    // -----------------------------------------------------------------
    /**
     * @param Tile[]      $pool         tile yang masih bebas (concealed + winningTile)
     * @param SetGroup[]  $fixedGroups  group dari open melds
     * @return Decomposition[]
     */
    private function decomposeStandard(array $pool, array $fixedGroups, int $needSets, Tile $winningTile): array
    {
        // Total tile di pool harus = needSets*3 + 2 (untuk pair).
        if (count($pool) !== $needSets * 3 + 2) return [];

        $byKey = $this->groupByKey($pool);
        // Konversi ke array of [key => count] terurut by key.
        $counts = [];
        foreach ($byKey as $k => $tiles) $counts[$k] = count($tiles);
        ksort($counts);

        $results = [];
        $this->recurse($byKey, $counts, [], $needSets, false, $results);

        // Konversi raw decompositions → Decomposition objects, klasifikasi wait.
        $waitClassifier = new WaitClassifier();
        $winKey = $this->key($winningTile);

        $final = [];
        foreach ($results as $raw) {
            // $raw['pair'] = SetGroup, $raw['sets'] = SetGroup[]
            $allGroups = array_merge($fixedGroups, [$raw['pair']], $raw['sets']);

            // Cari semua group yang BISA jadi wait (mengandung winningTile)
            // — dan generate satu Decomposition per kemungkinan wait.
            foreach ($allGroups as $idx => $g) {
                if (!$this->groupContainsKey($g, $winKey)) continue;
                if (in_array($g, $fixedGroups, true)) continue; // open meld bukan wait

                $waitType = $waitClassifier->classify($g, $winningTile);
                $final[] = new Decomposition(
                    shape: Decomposition::SHAPE_STANDARD,
                    groups: $allGroups,
                    waitType: $waitType,
                    waitGroupIndex: $idx,
                );
            }
        }

        return $final;
    }

    /**
     * Recursive: ambil pair, lalu set demi set.
     *
     * @param array<string,Tile[]>   $byKey   tile pool grouped by key
     * @param array<string,int>      $counts  remaining count per key (mutated copy)
     * @param SetGroup[]             $acc     set groups terakumulasi
     * @param int                    $needSets sisa set yang harus dibentuk
     * @param bool                   $pairTaken
     */
    private function recurse(array $byKey, array $counts, array $acc, int $needSets, bool $pairTaken, array &$results, ?SetGroup $pair = null): void
    {
        if (!$pairTaken) {
            // Pilih pair: untuk tiap key dengan count >= 2.
            foreach ($counts as $k => $c) {
                if ($c < 2) continue;
                $newCounts = $counts;
                $newCounts[$k] -= 2;
                if ($newCounts[$k] === 0) unset($newCounts[$k]);

                $pairTiles = array_slice($byKey[$k], 0, 2);
                $newPair = new SetGroup(SetGroup::KIND_PAIR, $pairTiles, isConcealed: true);

                $this->recurse($byKey, $newCounts, $acc, $needSets, true, $results, $newPair);
            }
            return;
        }

        if ($needSets === 0) {
            if (empty($counts)) {
                $results[] = ['pair' => $pair, 'sets' => $acc];
            }
            return;
        }

        if (empty($counts)) return;

        // Ambil key terkecil (deterministik).
        $keys = array_keys($counts);
        $firstKey = $keys[0];
        [$type, $value] = explode(':', $firstKey, 2);

        // Coba sebagai koutsu (triplet).
        if ($counts[$firstKey] >= 3) {
            $newCounts = $counts;
            $newCounts[$firstKey] -= 3;
            if ($newCounts[$firstKey] === 0) unset($newCounts[$firstKey]);

            $tilesUsed = array_slice($byKey[$firstKey], 0, 3);
            $g = new SetGroup(SetGroup::KIND_KOUTSU, $tilesUsed, isConcealed: true);
            $this->recurse($byKey, $newCounts, [...$acc, $g], $needSets - 1, true, $results, $pair);
        }

        // Coba sebagai chi (sequence) — hanya untuk suit man/pin/sou.
        if (in_array($type, ['man', 'pin', 'sou'], true)) {
            $v = (int)$value;
            if ($v >= 1 && $v <= 7) {
                $k1 = "$type:" . ($v + 1);
                $k2 = "$type:" . ($v + 2);
                if (($counts[$k1] ?? 0) >= 1 && ($counts[$k2] ?? 0) >= 1) {
                    $newCounts = $counts;
                    foreach ([$firstKey, $k1, $k2] as $kk) {
                        $newCounts[$kk]--;
                        if ($newCounts[$kk] === 0) unset($newCounts[$kk]);
                    }
                    $tilesUsed = [
                        $byKey[$firstKey][0],
                        $byKey[$k1][0],
                        $byKey[$k2][0],
                    ];
                    $g = new SetGroup(SetGroup::KIND_CHI, $tilesUsed, isConcealed: true);
                    $this->recurse($byKey, $newCounts, [...$acc, $g], $needSets - 1, true, $results, $pair);
                }
            }
        }
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------
    private function meldToGroup(Meld $m): SetGroup
    {
        $tiles = $m->getTiles();
        if ($m->isChi()) {
            return new SetGroup(SetGroup::KIND_CHI, $tiles, isConcealed: false);
        }
        if ($m->isPon()) {
            return new SetGroup(SetGroup::KIND_KOUTSU, $tiles, isConcealed: false);
        }
        if ($m->isAnkan()) {
            return new SetGroup(SetGroup::KIND_KANTSU, $tiles, isConcealed: true);
        }
        // Daiminkan & Shouminkan → open kantsu.
        return new SetGroup(SetGroup::KIND_KANTSU, $tiles, isConcealed: false);
    }

    private function key(Tile $t): string
    {
        return $t->getType() . ':' . $t->getValue();
    }

    /** @return array<string,int> */
    private function countByKey(array $tiles): array
    {
        $out = [];
        foreach ($tiles as $t) {
            $k = $this->key($t);
            $out[$k] = ($out[$k] ?? 0) + 1;
        }
        return $out;
    }

    /** @return array<string,Tile[]> */
    private function groupByKey(array $tiles): array
    {
        $out = [];
        foreach ($tiles as $t) {
            $out[$this->key($t)][] = $t;
        }
        return $out;
    }

    private function groupContainsKey(SetGroup $g, string $key): bool
    {
        foreach ($g->tiles as $t) {
            if ($this->key($t) === $key) return true;
        }
        return false;
    }
}
