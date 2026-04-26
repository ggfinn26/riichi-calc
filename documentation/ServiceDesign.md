# Desain dan Fundamental Layer Service

Dokumen ini menjelaskan struktur direktori yang direkomendasikan untuk `src/Service/` berdasarkan pembagian kategori service, serta prinsip-prinsip fundamental PHP (Best Practices) yang harus diterapkan saat menulis kode di layer Service.

---

## 1. Struktur Direktori `src/Service/`

Berdasarkan tiga kategori utama yang dijelaskan di `Service.md`, sangat disarankan untuk mengelompokkan file-file service ke dalam sub-direktori agar rapi dan mudah di- *maintain*.

```text
src/Service/
├── Calculation/                 # A. Kalkulator Matematis (Stateless)
│   ├── YakuEvaluator.php
│   ├── ScoreCalculator.php
│   ├── WaitCalculator.php
│   └── FuritenChecker.php
│
├── GameFlow/                    # B. Manajer Alur Permainan (Stateful/Orchestrator)
│   ├── PlayerActionService.php
│   └── GameProgressionService.php
│
└── Recommendation/              # C. Sistem Rekomendasi & Efisiensi
    ├── ShantenCalculator.php
    ├── VisibleTileTrackerService.php
    ├── DefenseEvaluatorService.php
    ├── ExpectedValueCalculator.php
    └── DiscardRecommendationService.php
```

---

## 2. Fundamental PHP yang Harus Diterapkan

1. **Strict Typing:** Gunakan `declare(strict_types=1);` di baris paling atas.
2. **Dependency Injection:** Jangan gunakan `new` untuk memanggil Repository/Service lain di dalam method. Gunakan Constructor Injection dengan Interface.
3. **Statelessness (Pure Functions):** Service kalkulasi tidak boleh menyimpan *state* di properti class. Output murni bergantung pada parameter input.
4. **Single Responsibility:** Satu service, satu tugas. Gunakan Exception untuk error handling.

---

## 3. Contoh Implementasi Fundamental per Service

Berikut adalah kerangka dasar (skeleton) untuk ke-11 service yang menunjukkan *signature method*, *dependency injection*, dan penerapan *strict types*.

### A. Kalkulator Matematis (Calculation)

#### 1. YakuEvaluator
```php
<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Service\Calculation;

use Dewa\Mahjong\Entity\Hand;
use Dewa\Mahjong\Entity\Tile;
use Dewa\Mahjong\Entity\GameContext;
use Dewa\Mahjong\Repository\YakuRepositoryInterface;
use Dewa\Mahjong\Repository\CustomYakuRepositoryInterface;

class YakuEvaluator
{
    // Dependency Injection via Constructor
    public function __construct(
        private YakuRepositoryInterface $yakuRepo,
        private CustomYakuRepositoryInterface $customYakuRepo
    ) {}

    /**
     * @return array<string, mixed> Daftar Yaku dan total Han
     */
    public function evaluate(Hand $hand, Tile $winningTile, GameContext $context, bool $isTsumo): array
    {
        $validYakus = [];
        $totalHan = 0;

        // Ambil daftar Yaku standar dari database
        $standardYakus = $this->yakuRepo->findAll();
        
        // Ambil Custom Yaku yang aktif di ronde ini
        $activeCustomYakus = $this->customYakuRepo->findByGameContextId($context->getId());

        // Logika pengecekan Yaku standar & Custom Yaku...

        return [
            'yakus' => $validYakus,
            'total_han' => $totalHan
        ];
    }
}
```

#### 2. ScoreCalculator
```php
<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Service\Calculation;

class ScoreCalculator
{
    // Service ini murni matematis, tidak butuh dependency ke Repository
    public function __construct() {}

    /**
     * @return array<string, int> Pembagian poin (main, non_dealer_pay, dealer_pay)
     */
    public function calculate(int $han, int $fu, bool $isDealer, bool $isTsumo, int $honba, int $riichiSticks): array
    {
        $basicPoints = 0;
        
        // Logika perhitungan Mangan, Haneman, dll...
        
        return [
            'total_score' => 0,
            'dealer_pays' => 0,
            'non_dealer_pays' => 0
        ];
    }
}
```

#### 3. WaitCalculator
```php
<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Service\Calculation;

use Dewa\Mahjong\Entity\Hand;
use Dewa\Mahjong\Entity\Tile;
use Dewa\Mahjong\Repository\TileRepositoryInterface;

class WaitCalculator
{
    // Butuh TileRepository untuk mengambil referensi 34 batu saat mengecek Machi
    public function __construct(
        private TileRepositoryInterface $tileRepo
    ) {}

    /**
     * @return Tile[] Daftar batu yang ditunggu (Machi)
     */
    public function calculateWaits(Hand $hand): array
    {
        $waits = [];
        
        if ($hand->getTotalPhysicalTilesCount() < 13) {
            return $waits;
        }

        // Ambil semua 34 jenis batu untuk disimulasikan satu per satu
        $allTiles = $this->tileRepo->findAll();

        // Algoritma deteksi Tenpai...

        return $waits;
    }
}
```

#### 4. FuritenChecker
```php
<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Service\Calculation;

use Dewa\Mahjong\Entity\Hand;
use Dewa\Mahjong\Entity\DiscardedPile;
use Dewa\Mahjong\Repository\DiscardedPileRepositoryInterface;

class FuritenChecker
{
    // Butuh DiscardedPileRepository untuk mengecek riwayat buangan pemain
    public function __construct(
        private DiscardedPileRepositoryInterface $discardRepo
    ) {}

    /**
     * @param Tile[] $waits
     */
    public function isFuriten(Hand $hand, array $waits, int $gameContextId, bool $missedRonThisTurn): bool
    {
        // Ambil riwayat buangan pemain dari database
        $playerDiscards = $this->discardRepo->findByGameContextAndUser($gameContextId, $hand->getUserId());

        // Cek Furiten Permanen (dari buangan sendiri)
        // Cek Furiten Sementara
        // Cek Furiten Riichi

        return false;
    }
}
```

---

### B. Manajer Alur Permainan (GameFlow)

#### 5. PlayerActionService
```php
<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Service\GameFlow;

use Dewa\Mahjong\Repository\HandRepositoryInterface;
use Dewa\Mahjong\Repository\GameContextRepositoryInterface;
use Dewa\Mahjong\Repository\DiscardedPileRepositoryInterface;

class PlayerActionService
{
    public function __construct(
        private HandRepositoryInterface $handRepo,
        private GameContextRepositoryInterface $gameContextRepo,
        private DiscardedPileRepositoryInterface $discardRepo
    ) {}

    public function processDiscard(int $gameContextId, int $userId, int $tileId): void
    {
        // 1. Ambil state
        // 2. Pindahkan batu dari Hand ke DiscardedPile
        // 3. Simpan via Repository
    }

    public function processRiichiDeclaration(int $handId, int $discardActionId): void
    {
        // Logika validasi dan update state Riichi
    }
}
```

#### 6. GameProgressionService
```php
<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Service\GameFlow;

use Dewa\Mahjong\Repository\GameContextRepositoryInterface;
use Dewa\Mahjong\Service\Calculation\WaitCalculator;

class GameProgressionService
{
    public function __construct(
        private GameContextRepositoryInterface $gameContextRepo,
        private WaitCalculator $waitCalculator
    ) {}

    public function resolveRoundEnd(int $gameContextId, string $endType): void
    {
        // Logika Agari, Ryuukyoku, Chombo
        // Hitung No-ten Bappu jika Ryuukyoku
        // Tentukan rotasi Dealer & Honba
        // Inisialisasi GameContext baru
    }
}
```

---

### C. Sistem Rekomendasi & Efisiensi (Recommendation)

#### 7. ShantenCalculator
```php
<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Service\Recommendation;

use Dewa\Mahjong\Entity\Hand;
use Dewa\Mahjong\Repository\TileRepositoryInterface;

class ShantenCalculator
{
    // Butuh TileRepository untuk referensi batu saat menghitung kombinasi
    public function __construct(
        private TileRepositoryInterface $tileRepo
    ) {}

    public function calculate(Hand $hand): int
    {
        // Hitung jarak minimum ke Tenpai (Standard, Chiitoitsu, Kokushi)
        // Return 0 jika Tenpai, 1 jika Iishanten, dst.
        return 0; 
    }
}
```

#### 8. VisibleTileTrackerService
```php
<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Service\Recommendation;

use Dewa\Mahjong\Entity\GameContext;
use Dewa\Mahjong\Repository\TileRepositoryInterface;

class VisibleTileTrackerService
{
    public function __construct(
        private TileRepositoryInterface $tileRepo
    ) {}

    /**
     * @return array<int, int> Map [tileId => sisaBatuYangBelumTerlihat]
     */
    public function getRemainingTilesProbabilities(GameContext $context): array
    {
        // Pindai DiscardedPile, DoraIndicators, dan Meld terbuka
        return [];
    }
}
```

#### 9. DefenseEvaluatorService
```php
<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Service\Recommendation;

use Dewa\Mahjong\Entity\Tile;
use Dewa\Mahjong\Entity\GameContext;
use Dewa\Mahjong\Repository\DiscardedPileRepositoryInterface;

class DefenseEvaluatorService
{
    // Butuh DiscardedPileRepository untuk mengecek Genbutsu (batu yang sudah dibuang lawan)
    public function __construct(
        private DiscardedPileRepositoryInterface $discardRepo
    ) {}

    /**
     * @return float Persentase bahaya (0.0 = Genbutsu/Aman, 100.0 = Sangat Bahaya)
     */
    public function evaluateDangerLevel(Tile $tileToDiscard, GameContext $context, int $targetUserId): float
    {
        // Ambil buangan lawan untuk mengecek Genbutsu
        $targetDiscards = $this->discardRepo->findByGameContextAndUser($context->getId(), $targetUserId);

        // Cek Genbutsu, Suji, Kabe terhadap targetUserId (yang sedang Riichi/Tenpai)
        return 0.0;
    }
}
```

#### 10. ExpectedValueCalculator
```php
<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Service\Recommendation;

use Dewa\Mahjong\Entity\Hand;
use Dewa\Mahjong\Service\Calculation\YakuEvaluator;

class ExpectedValueCalculator
{
    public function __construct(
        private YakuEvaluator $yakuEvaluator
    ) {}

    /**
     * @param Tile[] $ukeire Daftar batu yang bisa ditarik
     */
    public function calculateEV(Hand $hand, array $ukeire): float
    {
        // Simulasi YakuEvaluator untuk setiap kemungkinan Ukeire
        // Return estimasi skor rata-rata
        return 0.0;
    }
}
```

#### 11. DiscardRecommendationService
```php
<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Service\Recommendation;

use Dewa\Mahjong\Entity\Hand;
use Dewa\Mahjong\Entity\GameContext;

class DiscardRecommendationService
{
    public function __construct(
        private ShantenCalculator $shantenCalc,
        private VisibleTileTrackerService $trackerService,
        private DefenseEvaluatorService $defenseEval,
        private ExpectedValueCalculator $evCalc
    ) {}

    /**
     * @return array<int, array> Rekomendasi buangan beserta skor Speed, Defense, dan Value
     */
    public function getRecommendations(Hand $hand, GameContext $context): array
    {
        $recommendations = [];

        // Loop setiap batu di tangan
        // 1. Simulasikan buang batu X
        // 2. Hitung Shanten & Ukeire (Speed)
        // 3. Hitung Danger Level (Defense)
        // 4. Hitung EV (Value)
        // 5. Masukkan ke array $recommendations dan urutkan

        return $recommendations;
    }
}

---

## 4. Pola "Pabrik Fakta" pada YakuEvaluator (OCP)

Pertanyaan yang sangat bagus! Anda baru saja menyentuh prinsip penting dalam *Software Engineering* yang disebut **OCP (Open-Closed Principle)**: *Sistem harus terbuka untuk ditambahkan fitur baru, tapi tertutup dari modifikasi kode inti yang bisa merusaknya.*

Agar Anda tidak stres saat ingin menambahkan logika fakta baru (seperti `"isAllEvenNumbers"` atau `"isAllGreen"`), Anda **sama sekali tidak boleh** menyentuh fungsi algoritma *Backtracking* yang rumit tadi.

Cara termudah dan paling bersih adalah membuat **"Pabrik Fakta" (Fact Extractor)** terpisah.

Berikut adalah 3 langkah simpel bagaimana mendesainnya agar hidup Anda mudah di kemudian hari:

### Langkah 1: Tambahkan Helper di Entitas (Opsional tapi Sangat Dianjurkan)
Jika aturan baru Anda berkaitan dengan sifat fisik batu, tambahkan fungsi kecil langsung di objek batunya. Misalnya, Anda punya ide *Custom Yaku* "Genap Sempurna". 

Buka `src/Entity/Tile.php` dan tambahkan satu method kecil:
```php
    // Tambahkan di dalam Tile.php
    public function isEvenNumber(): bool 
    {
        if ($this->isHonor()) return false; // Angin/Naga bukan angka
        $numericValue = (int)$this->value;
        return $numericValue % 2 === 0; // Kembalikan true jika genap
    }
```

### Langkah 2: Buat Method "Pabrik Fakta" di YakuEvaluator
Pisahkan tugas *Backtracking* dengan tugas membuat *Fact Sheet*. Buat satu method khusus bernama `generateFactSheet`. 

Di sinilah **satu-satunya tempat** Anda perlu menulis kode jika ingin menambahkan syarat baru:

```php
    /**
     * Pabrik Fakta: Menerima tangan pemain dan kombinasi hasil backtracking,
     * lalu mengubahnya menjadi Array Fact Sheet.
     */
    private function generateFactSheet(Hand $hand, array $parsedCombinations): array 
    {
        // Siapkan kertas kosong
        $facts = [];

        // ==============================================================
        // AREA 1: FAKTA FISIK (Sangat mudah diedit/ditambah)
        // ==============================================================
        $semuaBatu = $hand->getTiles(); // Ambil semua batu di tangan
        
        $facts['isMenzen'] = $hand->isMenzen();
        
        // Cek Genap Sempurna (Ide Custom Yaku Anda)
        $isAllEven = true;
        foreach ($semuaBatu as $tile) {
            if (!$tile->isEvenNumber()) {
                $isAllEven = false;
                break;
            }
        }
        $facts['isAllEvenNumbers'] = $isAllEven;

        // Cek All Green (Ryuuiisou)
        // Cukup tambah logika loop singkat di sini untuk mengecek warna hijau...
        // $facts['isAllGreen'] = ...

        // ==============================================================
        // AREA 2: FAKTA KOMBINASI (Dari hasil Backtracking)
        // ==============================================================
        // Misalnya $parsedCombinations berisi [ ['type'=>'shuntsu'], ['type'=>'koutsu'] ]
        $shuntsuCount = 0;
        $koutsuCount = 0;
        
        foreach ($parsedCombinations as $set) {
            if ($set['type'] === 'shuntsu') $shuntsuCount++;
            if ($set['type'] === 'koutsu') $koutsuCount++;
        }
        
        $facts['shuntsuCount'] = $shuntsuCount;
        $facts['koutsuCount'] = $koutsuCount;

        return $facts;
    }
```

### Langkah 3: Eksekusi di Method Utama
Sekarang, method `evaluate` Anda yang dipanggil oleh *Controller* akan terlihat sangat rapi dan elegan seperti membaca buku:

```php
    public function evaluate(Hand $hand, Tile $winningTile, GameContext $context, bool $isTsumo, bool $isRiichi): array 
    {
        // 1. Suruh Backtracking membelah batu (Tugas Berat)
        $kombinasi = $this->calculateStandardCombinations($hand);

        // 2. Kirim hasilnya ke Pabrik Fakta (Tugas Ringan)
        $factSheet = $this->generateFactSheet($hand, $kombinasi);

        // 3. Looping semua Yaku dari Database
        $validYakus = [];
        $semuaYaku = $this->yakuRepo->findAll(); // Mengambil dari database
        
        foreach ($semuaYaku as $yaku) {
            $syaratJson = json_decode($yaku->getConditions(), true);
            
            // 4. Cocokkan menggunakan JSON Engine
            if ($this->meetsJsonConditions($factSheet, $syaratJson)) {
                $validYakus[] = $yaku;
            }
        }

        return [
            'yakus' => $validYakus,
            'total_han' => $this->hitungTotalHan($validYakus)
        ];
    }
```

### Kesimpulan: Kenapa Ini "Sangat Simpel"?

Jika minggu depan Anda ingin membuat Yaku kustom baru yang butuh parameter `"hasThreeDora"`, Anda **tidak perlu** membaca ulang atau takut merusak algoritma rekursif Backtracking. 

Anda cukup:
1. Buka `YakuEvaluator`.
2. Scroll langsung ke method `generateFactSheet`.
3. Tambahkan 3-4 baris kode di **AREA 1** untuk menghitung jumlah Dora di tangan.
4. Simpan ke array `$facts['hasThreeDora'] = ...`.
5. Selesai! Anda tinggal pergi ke database dan tulis JSON-nya.

Pendekatan ini mengisolasi *"Algoritma Matematika yang Sulit"* dengan *"Pengecekan Logika yang Mudah"*. Bagaimana menurut Anda struktur alur kerja di atas?
