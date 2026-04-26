<?php
declare(strict_types=1);
namespace Dewa\Mahjong\Service\Calculation;

use Dewa\Mahjong\Repository\HandRepositoryInterface;
use Dewa\Mahjong\Repository\CustomYakuRepositoryInterface;
use Dewa\Mahjong\Repository\YakuRepositoryInterface;
use Dewa\Mahjong\Repository\MeldRepositoryInterface;
use Dewa\Mahjong\Repository\GameContextRepositoryInterface;
use Dewa\Mahjong\Entity\Hand;
use Dewa\Mahjong\Entity\CustomYaku;
use Dewa\Mahjong\Entity\Yaku;
use Dewa\Mahjong\Entity\Meld;
use Dewa\Mahjong\Entity\Tile;
use Dewa\Mahjong\Entity\GameContext;

class YakuEvaluator {

    public function __construct(
        private HandRepositoryInterface $handRepo,
        private CustomYakuRepositoryInterface $customYakuRepo,
        private YakuRepositoryInterface $yakuRepo,
        private MeldRepositoryInterface $meldRepo,
        private GameContextRepositoryInterface $contextRepo
    ){}

    public function canStartSequence(int $tileId): bool{
        // Shuntsu hanya bisa dimulai dari angka 1 sampai 7 di setiap suit.
        // Angka 8 dan 9 tidak bisa memulai urutan 3 batu.
        // Batu Honor (28-34) sama sekali tidak bisa menjadi Shuntsu.
        
        if ($tileId >= 1 && $tileId <= 7) return true;   // Man 1-7
        if ($tileId >= 10 && $tileId <= 16) return true; // Pin 1-7
        if ($tileId >= 19 && $tileId <= 25) return true; // Sou 1-7
        
        return false;
    }
    
    /**
     * Algoritma Backtracking Inti untuk memecah batu
     * * @param array<int, int> $freqMap Peta frekuensi sisa batu [TileId => Jumlah]
     * @param bool $hasPair Apakah kombinasi ini sudah menemukan 1 Pair?
     * @return array Array berisi semua kemungkinan kombinasi valid
     */
    private function calculateStandardCombinations(array $freqMap, bool $hasPair = false): array 
    {
        // 1. Cari ID batu pertama yang jumlahnya masih > 0
        $firstTileId = -1;
        foreach ($freqMap as $id => $count) {
            if ($count > 0) {
                $firstTileId = $id;
                break;
            }
        }

        // BASE CASE: Jika semua batu sudah habis terpakai (0), 
        // berarti kita berhasil menemukan 1 kombinasi yang valid!
        if ($firstTileId === -1) {
            // Jika tidak punya Pair (misal karena error input), anggap tidak valid
            return $hasPair ? [[]] : []; 
        }

        $validCombinations = [];

        // RUTE A: Coba jadikan Pair (Jantou)
        // Syarat: Belum punya pair, dan batunya minimal ada 2
        if (!$hasPair && $freqMap[$firstTileId] >= 2) {
            $freqMap[$firstTileId] -= 2; // Ambil 2 batu
            
            // Rekursif: Lanjutkan mencari sisa batu
            $subCombs = $this->calculateStandardCombinations($freqMap, true);
            foreach ($subCombs as $comb) {
                $comb[] = ['type' => 'pair', 'tileId' => $firstTileId];
                $validCombinations[] = $comb;
            }
            
            $freqMap[$firstTileId] += 2; // BACKTRACK: Kembalikan 2 batu
        }

        // RUTE B: Coba jadikan Koutsu (Triplet / 3 Kembar)
        // Syarat: Batunya minimal ada 3
        if ($freqMap[$firstTileId] >= 3) {
            $freqMap[$firstTileId] -= 3; // Ambil 3 batu
            
            $subCombs = $this->calculateStandardCombinations($freqMap, $hasPair);
            foreach ($subCombs as $comb) {
                $comb[] = ['type' => 'koutsu', 'tileId' => $firstTileId];
                $validCombinations[] = $comb;
            }
            
            $freqMap[$firstTileId] += 3; // BACKTRACK: Kembalikan 3 batu
        }

        // RUTE C: Coba jadikan Shuntsu (Sequence / 3 Urutan)
        // Syarat: Bisa memulai sequence, dan batu ID, ID+1, ID+2 semuanya tersedia
        if ($this->canStartSequence($firstTileId) && 
            isset($freqMap[$firstTileId + 1], $freqMap[$firstTileId + 2]) &&
            $freqMap[$firstTileId] >= 1 && 
            $freqMap[$firstTileId + 1] >= 1 && 
            $freqMap[$firstTileId + 2] >= 1) 
        {
            $freqMap[$firstTileId]--;
            $freqMap[$firstTileId + 1]--;
            $freqMap[$firstTileId + 2]--; // Ambil 3 batu urutan
            
            $subCombs = $this->calculateStandardCombinations($freqMap, $hasPair);
            foreach ($subCombs as $comb) {
                // Catat batu pertama sebagai representasi start urutannya
                $comb[] = ['type' => 'shuntsu', 'tileId' => $firstTileId];
                $validCombinations[] = $comb;
            }
            
            $freqMap[$firstTileId]++;
            $freqMap[$firstTileId + 1]++;
            $freqMap[$firstTileId + 2]++; // BACKTRACK: Kembalikan 3 batu urutan
        }

        // Kembalikan semua rute yang berhasil membelah batu sampai habis
        return $validCombinations;
    }

    /**
     * Konversi batu di tangan (+ winning tile) menjadi freqMap [tileId => jumlah].
     * Hanya batu tertutup di tangan — Meld terbuka sudah diklasifikasikan sendiri.
     */
    private function buildFreqMap(Hand $hand, Tile $winningTile): array
    {
        $freqMap = [];
        foreach ($hand->getTiles() as $tile) {
            $id = $tile->getId();
            $freqMap[$id] = ($freqMap[$id] ?? 0) + 1;
        }
        $winId = $winningTile->getId();
        $freqMap[$winId] = ($freqMap[$winId] ?? 0) + 1;
        return $freqMap;
    }

    /**
     * Pabrik Fakta: mengubah tangan + satu hasil backtracking menjadi Fact Sheet.
     * Inilah SATU-SATUNYA tempat yang perlu disentuh saat menambah syarat Yaku baru.
     *
     * @param array $parsedCombination Satu kombinasi valid dari calculateStandardCombinations()
     * @return array<string, mixed>
     */
    private function generateFactSheet(Hand $hand, array $parsedCombination, bool $isTsumo, bool $isRiichi, Tile $winningTile): array
    {
        $facts = [];

        // ==============================================================
        // AREA 1: FAKTA SITUASIONAL
        // ==============================================================
        $facts['isMenzen'] = $hand->isMenzen();
        $facts['isRiichi']  = $isRiichi;
        $facts['isTsumo']   = $isTsumo;
        $facts['isDealer']  = $hand->isDealer();

        // ==============================================================
        // AREA 2: FAKTA FISIK BATU
        // ==============================================================
        $allMeldTiles = [];
        foreach ($hand->getMelds() as $meld) {
            foreach ($meld->getTiles() as $tile) {
                $allMeldTiles[] = $tile;
            }
        }
        $allTiles = array_merge($hand->getTiles(), $allMeldTiles, [$winningTile]);

        $isAllEven              = true;
        $isAllGreen             = true;
        $isAllTerminals         = true;
        $isAllHonors            = true;
        $isAllTerminalsOrHonors = true;
        $hasNoTerminalsOrHonors = true;
        $suits = [];

        foreach ($allTiles as $tile) {
            if (!$tile->isEvenNumber())                          $isAllEven = false;
            if (!$tile->isGreen())                               $isAllGreen = false;
            if (!$tile->isTerminal())                            $isAllTerminals = false;
            if (!$tile->isHonor())                               $isAllHonors = false;
            if (!$tile->isTerminal() && !$tile->isHonor())       $isAllTerminalsOrHonors = false;
            if ($tile->isTerminal() || $tile->isHonor())         $hasNoTerminalsOrHonors = false;
            if (!$tile->isHonor()) {
                $suits[$tile->getType()] = true;
            }
        }

        $facts['isAllEvenNumbers']       = $isAllEven;
        $facts['isAllGreen']             = $isAllGreen;         // Ryuuiisou
        $facts['isAllTerminals']         = $isAllTerminals;
        $facts['isAllHonors']            = $isAllHonors;
        $facts['isAllTerminalsOrHonors'] = $isAllTerminalsOrHonors; // Honroutou
        $facts['hasNoTerminalsOrHonors'] = $hasNoTerminalsOrHonors; // Tanyao
        $facts['suitCount']              = count($suits);       // 1 = Chinitsu/Honitsu kandidat

        // ==============================================================
        // AREA 3: FAKTA KOMBINASI (Closed dari Backtracking + Open Melds)
        // ==============================================================
        $shuntsuCount = 0;
        $koutsuCount  = 0;
        $kanCount     = 0;
        $pairTileId   = null;

        foreach ($parsedCombination as $set) {
            match ($set['type']) {
                'shuntsu' => $shuntsuCount++,
                'koutsu'  => $koutsuCount++,
                'pair'    => $pairTileId = $set['tileId'],
                default   => null,
            };
        }

        foreach ($hand->getMelds() as $meld) {
            if ($meld->isChi())                                                             $shuntsuCount++;
            elseif ($meld->isPon())                                                        $koutsuCount++;
            elseif ($meld->isAnkan() || $meld->isDaiminkan() || $meld->isShouminkan())    $kanCount++;
        }

        $facts['shuntsuCount'] = $shuntsuCount;
        $facts['koutsuCount']  = $koutsuCount;
        $facts['kanCount']     = $kanCount;
        $facts['pairTileId']   = $pairTileId;

        return $facts;
    }

    /**
     * Hitung total Han dari daftar Yaku yang valid.
     *
     * @param array<Yaku|CustomYaku> $yakus
     */
    private function hitungTotalHan(array $yakus, bool $isMenzen): int
    {
        $total = 0;
        foreach ($yakus as $yaku) {
            $total += $isMenzen ? $yaku->getHanClosed() : $yaku->getHanOpened();
        }
        return $total;
    }

    /**
     * Entry point utama: evaluasi semua Yaku (standar + custom) untuk satu kemenangan.
     *
     * @return array{yakus: array<Yaku|CustomYaku>, total_han: int}
     */
    public function evaluate(Hand $hand, Tile $winningTile, GameContext $context, bool $isTsumo, bool $isRiichi): array
    {
        // 1. Bangun freqMap lalu jalankan Backtracking (Tugas Berat)
        $freqMap         = $this->buildFreqMap($hand, $winningTile);
        $allCombinations = $this->calculateStandardCombinations($freqMap);

        $validYakus = [];

        // 2a. Cek Yaku standar
        foreach ($this->yakuRepo->findAll() as $yaku) {
            $conditions = json_decode($yaku->getConditions() ?? '[]', true) ?? [];

            if ($this->anyComboMeetsFacts($hand, $allCombinations, $isTsumo, $isRiichi, $winningTile, $conditions)) {
                $validYakus[] = $yaku;
            }
        }

        // 2b. Cek Custom Yaku yang aktif di ronde ini
        foreach ($this->customYakuRepo->findByGameContextId($context->getId()) as $customYaku) {
            if ($customYaku->isDeleted()) continue;

            if ($this->anyComboMeetsFacts($hand, $allCombinations, $isTsumo, $isRiichi, $winningTile, $customYaku->getConditions())) {
                $validYakus[] = $customYaku;
            }
        }

        return [
            'yakus'     => $validYakus,
            'total_han' => $this->hitungTotalHan($validYakus, $hand->isMenzen()),
        ];
    }

    /**
     * Periksa apakah SETIDAKNYA SATU kombinasi backtracking memenuhi syarat Yaku.
     * Dipisah ke method sendiri agar evaluate() tetap ringkas.
     */
    private function anyComboMeetsFacts(Hand $hand, array $allCombinations, bool $isTsumo, bool $isRiichi, Tile $winningTile, array $conditions): bool
    {
        if (empty($allCombinations)) {
            // Tangan tidak punya kombinasi standar (misal belum Tenpai), cek tanpa fakta kombinasi
            $factSheet = $this->generateFactSheet($hand, [], $isTsumo, $isRiichi, $winningTile);
            return $this->meetsJsonConditions($factSheet, $conditions);
        }

        foreach ($allCombinations as $combination) {
            $factSheet = $this->generateFactSheet($hand, $combination, $isTsumo, $isRiichi, $winningTile);
            if ($this->meetsJsonConditions($factSheet, $conditions)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Mesin Evaluator Berbasis Aturan (JSON Engine)
     * * @param array $handFacts Fakta yang diekstrak dari tangan pemain
     * @param array $yakuConditions Syarat Yaku (hasil json_decode dari database)
     * @return bool True jika tangan memenuhi semua syarat Yaku ini
     */
    private function meetsJsonConditions(array $handFacts, array $yakuConditions): bool 
    {
        // Jika yaku tidak memiliki syarat khusus (misal Yaku situasional seperti Riichi), anggap lolos
        if (empty($yakuConditions)) {
            return true;
        }

        foreach ($yakuConditions as $factKey => $requiredCondition) {
            
            // 1. Cek apakah fakta yang diminta ada di dalam Fact Sheet pemain
            if (!array_key_exists($factKey, $handFacts)) {
                return false; // Syarat minta sesuatu yang tidak ada di tangan, langsung gagal
            }

            $actualFactValue = $handFacts[$factKey];

            // 2. Jika syaratnya adalah nilai pasti (misal: "isMenzen": true atau "shuntsuCount": 4)
            if (!is_array($requiredCondition)) {
                if ($actualFactValue !== $requiredCondition) {
                    return false;
                }
                continue; // Syarat ini lolos, lanjut ke syarat berikutnya
            }

            // 3. Jika syaratnya menggunakan operator kompleks (misal: "koutsuCount": { ">=": 3 })
            foreach ($requiredCondition as $operator => $targetValue) {
                switch ($operator) {
                    case '>':
                        if (!($actualFactValue > $targetValue)) return false;
                        break;
                    case '>=':
                        if (!($actualFactValue >= $targetValue)) return false;
                        break;
                    case '<':
                        if (!($actualFactValue < $targetValue)) return false;
                        break;
                    case '<=':
                        if (!($actualFactValue <= $targetValue)) return false;
                        break;
                    case '!=':
                        if (!($actualFactValue !== $targetValue)) return false;
                        break;
                    case 'in':
                        // Misal syarat: "waitType": { "in": ["ryanmen", "machi"] }
                        if (!in_array($actualFactValue, $targetValue, true)) return false;
                        break;
                    default:
                        // Jika operator tidak dikenali
                        return false; 
                }
            }
        }

        // Jika semua loop syarat berhasil dilewati tanpa return false, berarti sah!
        return true;
    }

}


