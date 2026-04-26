<?php

namespace Dewa\Mahjong\Entity;

class Hand
{
    private int $id;
    private int $gameContextId;
    private int $userId;

    private bool $isDealer;
    private bool $isRiichiDeclared;

    /** @var Tile[] */
    private array $tiles; // Hanya untuk batu yang masih ada di dalam tangan (tertutup / belum jadi Meld)

    /** @var Meld[] */
    private array $melds; // Wadah untuk menyimpan aksi Chi/Pon/Kan

    private ?int $riichiDiscardId;
    private ?int $nagashiManganDiscardId;

    // Masukkan isDealer ke parameter agar bisa ditentukan saat membuat tangan
    public function __construct(int $id, int $gameContextId, int $userId, bool $isDealer = false)
    {
        $this->id = $id;
        $this->gameContextId = $gameContextId;
        $this->userId = $userId;

        // INISIALISASI WAJIB UNTUK MENCEGAH FATAL ERROR PHP
        $this->isDealer = $isDealer;
        $this->isRiichiDeclared = false;
        $this->tiles = [];
        $this->melds = [];
        $this->riichiDiscardId = null;
        $this->nagashiManganDiscardId = null;
    }

    // --- GETTERS ---

    public function getId(): int
    {
        return $this->id;
    }
    public function getGameContextId(): int
    {
        return $this->gameContextId;
    }

    /**
     * Dipakai oleh repository setelah INSERT untuk mempromosikan entity
     * dari transient ke persisted (in-place), agar collection di parent
     * tidak perlu di-rebuild.
     */
    public function setId(int $id): void
    {
        $this->id = $id;
    }

    /**
     * Sinkronisasi parent ID saat cascade save dari GameContextRepository:
     * Hand dirakit di memori sebelum parent punya ID, jadi perlu di-relink
     * setelah parent ter-INSERT.
     */
    public function setGameContextId(int $gameContextId): void
    {
        $this->gameContextId = $gameContextId;
    }
    public function getUserId(): int
    {
        return $this->userId;
    }
    public function isDealer(): bool
    {
        return $this->isDealer;
    }
    public function isRiichiDeclared(): bool
    {
        return $this->isRiichiDeclared;
    }
    public function getRiichiDiscardId(): ?int
    {
        return $this->riichiDiscardId;
    }
    public function getNagashiManganDiscardId(): ?int
    {
        return $this->nagashiManganDiscardId;
    }

    /** @return Tile[] */
    public function getTiles(): array
    {
        return $this->tiles;
    }

    /** @return Meld[] */
    public function getMelds(): array
    {
        return $this->melds;
    }

    // --- SMART ACTION METHODS ---

    /**
     * Memasukkan batu ke dalam tangan (saat setup awal atau saat Tsumo)
     */
    public function addTile(Tile $tile): void
    {
        $this->tiles[] = $tile;
    }

    /**
     * Mendaftarkan Meld yang sudah divalidasi ke dalam tangan
     */
    public function addMeld(Meld $meld): void
    {
        $this->melds[] = $meld;
    }

    /**
     * Aksi mendeklarasikan Riichi
     */
    public function declareRiichi(int $discardActionId): void
    {
        // Dalam Mahjong, tangan harus tertutup (Menzenchin) untuk bisa Riichi
        if (!$this->isMenzen()) {
            throw new \Exception("Tidak bisa Riichi: Tangan sudah terbuka!");
        }

        $this->isRiichiDeclared = true;
        $this->riichiDiscardId = $discardActionId;
    }

    /**
     * Restore status Riichi dari DB tanpa cek Menzen (faktanya sudah persisted).
     */
    public function setRiichiDeclared(bool $isDeclared, ?int $discardActionId): void
    {
        $this->isRiichiDeclared = $isDeclared;
        $this->riichiDiscardId = $discardActionId;
    }

    public function setNagashiManganDiscardId(?int $id): void
    {
        $this->nagashiManganDiscardId = $id;
    }

    // --- HELPER LOGIC ---

    /**
     * Cek apakah tangan masih tertutup murni (Menzenchin).
     * Jika ada Meld terbuka (Chi, Pon, Daiminkan), maka false.
     * Ankan (Kan Tertutup) tidak membatalkan Menzenchin.
     */
    public function isMenzen(): bool
    {
        foreach ($this->melds as $meld) {
            // Jika ada satu saja meld yang tidak closed (terbuka), maka Menzen batal
            if (!$meld->isClosed()) {
                return false;
            }
        }
        return true;
    }
    /**
     * Menghitung total LOGIS batu (Berguna untuk mengecek pola kemenangan 14 batu)
     * Di sini, semua Meld (termasuk Kan) dianggap setara dengan 3 batu.
     */
    public function getTotalTilesCount(): int
    {
        $count = count($this->tiles);
        foreach ($this->melds as $meld) {
            // Mau itu Chi, Pon, atau Kan, secara logis dihitung sebagai 3 batu (1 set)
            $count += 3;
        }
        return $count;
    }

    /**
     * Menghitung total FISIK batu (termasuk Kan yang isinya 4)
     * Berguna untuk validasi input di kalkulator.
     */
    public function getTotalPhysicalTilesCount(): int
    {
        $count = count($this->tiles);
        foreach ($this->melds as $meld) {
            $count += count($meld->getTiles());
        }
        return $count;
    }

    /**
     * Memvalidasi apakah jumlah batu masuk akal sesuai aturan Mahjong
     * Sangat berguna untuk Kalkulator / Prediktor agar menolak input user yang ngawur
     */
    public function isValidTileCount(): bool
    {
        $kanCount = 0;
        foreach ($this->melds as $meld) {
            // Jika meld adalah Ankan, Daiminkan, atau Shouminkan
            if (in_array($meld->getType(), ['ankan', 'daiminkan', 'shouminkan'])) {
                $kanCount++;
            }
        }

        // Tangan normal maksimal 14 fisik (13 + 1 drawn tile).
        // Setiap Kan memberikan hak +1 batu ekstra dari Rinshan.
        $maxAllowedPhysicalTiles = 14 + $kanCount;

        $currentPhysicalTiles = $this->getTotalPhysicalTilesCount();

        // Prediktor mengizinkan kurang dari maksimal (misal baru 13 batu), 
        // tapi TIDAK BOLEH melebihi batas yang ditentukan oleh Kan.
        return $currentPhysicalTiles <= $maxAllowedPhysicalTiles;
    }
}