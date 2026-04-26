<?php

namespace Dewa\Mahjong\Entity;

class DiscardedPile
{
    private int $id;
    private int $gameContextId;
    private int $userId;
    private int $turnOrder;
    private bool $isRiichiDeclare; // Apakah ini batu yang dibuang mendatar saat deklarasi Riichi?
    private bool $isTsumo;         // True = Tsumogiri (langsung buang yang baru ditarik), False = Tebidashi (buang dari dalam tangan)
    private int $orderIndex;       // Urutan buangan ke berapa (1-24)

    /** @var Tile */
    private Tile $tile; // Ganti $tileId menjadi objek Tile langsung!

    public function __construct(
        int $id,
        int $gameContextId,
        int $userId,
        Tile $tile,
        int $turnOrder,
        bool $isRiichiDeclare,
        bool $isTsumo,
        int $orderIndex
    ) {
        $this->id = $id;
        $this->gameContextId = $gameContextId;
        $this->userId = $userId;
        $this->tile = $tile;
        $this->turnOrder = $turnOrder;
        $this->isRiichiDeclare = $isRiichiDeclare;
        $this->isTsumo = $isTsumo;
        $this->orderIndex = $orderIndex; // Order index dimasukkan ke constructor
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
     * dari transient ke persisted (in-place), agar discard pile di parent
     * tidak ter-INSERT ulang pada save() berikutnya.
     */
    public function setId(int $id): void
    {
        $this->id = $id;
    }

    /**
     * Sinkronisasi parent ID saat cascade save dari GameContextRepository:
     * discard yang baru di-register lewat registerDiscard() belum tahu
     * game_context_id final jika parent baru ter-INSERT bersamaan.
     */
    public function setGameContextId(int $gameContextId): void
    {
        $this->gameContextId = $gameContextId;
    }
    public function getUserId(): int
    {
        return $this->userId;
    }
    public function getTurnOrder(): int
    {
        return $this->turnOrder;
    }
    public function isRiichiDeclare(): bool
    {
        return $this->isRiichiDeclare;
    }
    public function isTsumo(): bool
    {
        return $this->isTsumo;
    }
    public function getOrderIndex(): int
    {
        return $this->orderIndex;
    }

    /**
     * Langsung mengembalikan objek Tile, jadi Service nanti bisa langsung ngecek:
     * $discardAction->getTile()->isTerminal()
     */
    public function getTile(): Tile
    {
        return $this->tile;
    }
}