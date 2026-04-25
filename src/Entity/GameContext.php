<?php

namespace Dewa\Mahjong\Entity;

class GameContext
{
    private int $id;
    private int $gameId;

    // --- STATUS PERMAINAN ---
    private string $status;
    private int $roundNumber;       // Putaran (1-4)
    private string $roundWind;      // 'east' atau 'south' (Penting untuk Yakuhai)
    private int $honba;             // Jumlah stik repetisi (Penting untuk kalkulasi poin)
    private int $riichiSticks;      // Jumlah stik 1000 poin di tengah meja

    // --- POSISI PEMAIN ---
    private int $dealerId;
    private int $currentTurnUserId;
    private int $nextTurnOrderIndex;

    // --- BATU DI MEJA ---
    private int $leftWallTiles;     // Sisa batu di dinding (Penting untuk cek status dasar/akhir game)

    /** @var Tile[] */
    private array $doraIndicators;  // Simpan objek batunya langsung!

    /** @var DiscardedPile[] */
    private array $discardPile;     // Semua buangan di atas meja

    /** @var Hand[] */
    private array $hands;           // Tangan milik 4 pemain

    // --- RULES KUSTOM ---
    /** @var CustomYaku[] */
    private array $activeCustomYakus;
    private int $kanCount = 0; // Melacak jumlah Kan di meja (Maksimal 4)


    public function __construct(
        int $id,
        int $gameId,
        int $roundNumber,
        string $roundWind,
        int $dealerId,
        int $currentTurnUserId,
        string $status,
        int $honba = 0,
        int $riichiSticks = 0
    ) {
        $this->id = $id;
        $this->gameId = $gameId;
        $this->roundNumber = $roundNumber;
        $this->roundWind = $roundWind;
        $this->dealerId = $dealerId;
        $this->currentTurnUserId = $currentTurnUserId;
        $this->status = $status;
        $this->honba = $honba;
        $this->riichiSticks = $riichiSticks;

        // Inisialisasi default agar tidak error
        $this->nextTurnOrderIndex = 1;
        $this->leftWallTiles = 70; // Standar sisa batu awal setelah 13x4 ditarik + 14 dead wall
        $this->doraIndicators = [];
        $this->discardPile = [];
        $this->hands = [];
        $this->activeCustomYakus = [];
        $this->kanCount = 0;
    }

    // --- GETTERS ---
    public function getId(): int
    {
        return $this->id;
    }
    public function getGameId(): int
    {
        return $this->gameId;
    }
    public function getRoundNumber(): int
    {
        return $this->roundNumber;
    }
    public function getRoundWind(): string
    {
        return $this->roundWind;
    }
    public function getHonba(): int
    {
        return $this->honba;
    }
    public function getRiichiSticks(): int
    {
        return $this->riichiSticks;
    }
    public function getDealerId(): int
    {
        return $this->dealerId;
    }
    public function getCurrentTurnUserId(): int
    {
        return $this->currentTurnUserId;
    }
    public function getStatus(): string
    {
        return $this->status;
    }
    public function getNextTurnOrderIndex(): int
    {
        return $this->nextTurnOrderIndex;
    }
    public function getLeftWallTiles(): int
    {
        return $this->leftWallTiles;
    }

    /** @return Tile[] */
    public function getDoraIndicators(): array
    {
        return $this->doraIndicators;
    }

    /** @return DiscardedPile[] */
    public function getDiscardPile(): array
    {
        return $this->discardPile;
    }

    /** @return Hand[] */
    public function getHands(): array
    {
        return $this->hands;
    }

    /** @return CustomYaku[] */
    public function getActiveCustomYakus(): array
    {
        return $this->activeCustomYakus;
    }

    // --- SMART ACTIONS ---

    public function addDoraIndicator(Tile $tile): void
    {
        if (count($this->doraIndicators) >= 5) {
            throw new \Exception("Maksimal hanya ada 5 Dora indikator.");
        }
        $this->doraIndicators[] = $tile;
    }

    public function registerDiscard(DiscardedPile $discard): void
    {
        $this->discardPile[] = $discard;
        $this->nextTurnOrderIndex++;
    }

    public function addHand(Hand $hand): void
    {
        if (count($this->hands) >= 4) {
            throw new \Exception("Meja Mahjong maksimal 4 pemain.");
        }
        $this->hands[$hand->getUserId()] = $hand;
    }

    public function addRiichiStick(): void
    {
        $this->riichiSticks++;
    }

    public function isGameActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Aksi khusus pada meja ketika terjadi Kan
     */
    public function processKanWallAdjustment(Tile $newDoraIndicator): void
    {
        if ($this->kanCount >= 4) {
            throw new \Exception("Maksimal Kan di meja adalah 4 (Suukaikan). Permainan harusnya Draw!");
        }

        if ($this->leftWallTiles <= 0) {
            throw new \Exception("Tidak bisa Kan, dinding sudah habis!");
        }

        $this->kanCount++;

        // Dinding biasa berkurang 1 untuk menutupi Dead Wall yang ditarik (Rinshan)
        $this->leftWallTiles--;

        // Buka indikator Dora baru
        $this->addDoraIndicator($newDoraIndicator);
    }

    /**
     * Mengembalikan jumlah total batu yang tersisa di tangan + di dinding
     * Ini digunakan untuk mendeteksi kondisi Tsumo/Ryuukyoku (Draw)
     */
    public function calculateTotalRemainingTiles(): int
    {
        $tilesInHands = 0;
        foreach ($this->hands as $hand) {
            // Hitung batu di tangan (termasuk yang tersembunyi dari pungutan Kan)
            $tilesInHands += $hand->getTotalPhysicalTilesCount();
        }

        // Total: Batu di tangan + Sisa dinding + Batu Dora yang sedang terbuka di meja
        // Catatan: Batu Dora juga diambil dari tumpukan batu, jadi harus dihitung total sisa
        return $tilesInHands + $this->leftWallTiles + count($this->doraIndicators);
    }

    /**
     * Cek apakah kondisi permainan sudah berakhir (Ryuukyoku)
     */
    public function isDrawConditionMet(): bool
    {
        // 1. Dinding Habis: Jika batu di dinding tersisa 0, game berakhir Draw.
        if ($this->leftWallTiles <= 0) {
            return true;
        }

        // 2. Suukaikan (Empat Kan): Jika sudah 4 Kan terjadi di meja.
        if ($this->kanCount >= 4) {
            return true;
        }

        // 3. Hitung total semua batu yang tersisa di permainan
        $totalTiles = $this->calculateTotalRemainingTiles();

        // Jika totalnya 0, artinya semua batu sudah di tangan atau dibuang.
        return $totalTiles === 0;
    }
    /**
     * Aksi ketika pemain menarik batu dari dinding biasa (Tsumo)
     */
    public function drawTileFromWall(): void
    {
        if ($this->leftWallTiles <= 0) {
            throw new \Exception("Dinding sudah habis! Permainan berakhir seri (Ryuukyoku).");
        }
        $this->leftWallTiles--;
    }

}