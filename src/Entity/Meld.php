<?php

namespace Dewa\Mahjong\Entity;

class Meld
{
    private int $id;
    private int $gameContextId;
    private int $userId;
    private int $handId;
    private string $type;
    private bool $isClosed;

    /** @var Tile[] */
    private array $tiles;

    public function __construct(int $id, int $gameContextId, int $userId, int $handId, string $type, bool $isClosed, array $tiles)
    {
        $this->id = $id;
        $this->gameContextId = $gameContextId;
        $this->userId = $userId;
        $this->handId = $handId;
        $this->type = $type;
        $this->isClosed = $isClosed;
        $this->tiles = $tiles;
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
    public function getUserId(): int
    {
        return $this->userId;
    }
    public function getHandId(): int
    {
        return $this->handId;
    }
    public function getType(): string
    {
        return $this->type;
    }
    public function isClosed(): bool
    {
        return $this->isClosed;
    }

    /** @return Tile[] */
    public function getTiles(): array
    {
        return $this->tiles;
    }

    // --- SMART LOGIC / HELPERS ---

    public function isChi(): bool
    {
        return $this->type === 'chi';
    }
    public function isPon(): bool
    {
        return $this->type === 'pon';
    }
    public function isAnkan(): bool
    {
        return $this->type === 'ankan';
    }
    public function isDaiminkan(): bool
    {
        return $this->type === 'daiminkan';
    }
    public function isShouminkan(): bool
    {
        return $this->type === 'shouminkan';
    }

    /**
     * Validasi Internal Meld:
     * Chi/Pon harus 3 batu. Semua jenis Kan harus 4 batu.
     * Mengembalikan false jika user menginput data cacat di kalkulator.
     */
    public function isValidMeldCount(): bool
    {
        $count = count($this->tiles);

        if ($this->isChi() || $this->isPon()) {
            return $count === 3;
        }

        if ($this->isAnkan() || $this->isDaiminkan() || $this->isShouminkan()) {
            return $count === 4;
        }

        return false; // Tipe tidak valid
    }
}