<?php

namespace Dewa\Mahjong\Entity;

class CustomYaku
{
    private int $id;
    private string $name;
    private string $description;
    private int $hanClosed;
    private int $hanOpened;
    private bool $isYakuman;

    /** * @var array Kumpulan aturan logis dengan key baku. 
     * Contoh: ['allowed_suits' => ['pin'], 'must_be_menzen' => true]
     */
    private array $conditions;

    private bool $isActive;
    private \DateTime $createdAt;
    private \DateTime $updatedAt;
    private int $createdByUserId;

    public function __construct(int $id, string $name, string $description, int $hanClosed, int $hanOpened, bool $isYakuman, array $conditions, bool $isActive, \DateTime $createdAt, \DateTime $updatedAt, int $createdByUserId)
    {
        $this->id = $id;
        $this->name = $name;
        $this->description = $description;
        $this->hanClosed = $hanClosed;
        $this->hanOpened = $hanOpened;
        $this->isYakuman = $isYakuman;
        $this->conditions = $conditions;
        $this->isActive = $isActive;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->createdByUserId = $createdByUserId;
    }

    // ... (Getters yang Anda buat sudah benar dan dipertahankan) ...
    public function getId(): int
    {
        return $this->id;
    }
    public function getName(): string
    {
        return $this->name;
    }
    public function getDescription(): string
    {
        return $this->description;
    }
    public function getHanClosed(): int
    {
        return $this->hanClosed;
    }
    public function getHanOpened(): int
    {
        return $this->hanOpened;
    }
    public function isYakuman(): bool
    {
        return $this->isYakuman;
    }
    public function getConditions(): array
    {
        return $this->conditions;
    }
    public function isActive(): bool
    {
        return $this->isActive;
    }
    public function getCreatedAt(): \DateTime
    {
        return $this->createdAt;
    }
    public function getUpdatedAt(): \DateTime
    {
        return $this->updatedAt;
    }
    public function getCreatedByUserId(): int
    {
        return $this->createdByUserId;
    }

    // --- SMART ACTION METHODS ---

    private function markAsUpdated(): void
    {
        $this->updatedAt = new \DateTime();
    }

    /**
     * Mematikan atau menyalakan Custom Yaku ini
     */
    public function toggleActive(): void
    {
        $this->isActive = !$this->isActive;
        $this->markAsUpdated();
    }

    /**
     * Jika user mengedit deskripsi atau aturan (kondisi)
     */
    public function updateRules(string $newDescription, array $newConditions): void
    {
        $this->description = $newDescription;
        $this->conditions = $newConditions;
        $this->markAsUpdated();
    }
}