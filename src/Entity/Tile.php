<?php

namespace Dewa\Mahjong\Entity;

class Tile
{
    private int $id;
    private string $name;
    private string $value;
    private string $unicode;
    private string $type;    // 'man', 'pin', 'sou', atau 'honor'
    private string $color;

    // Properti boolean dihapus karena sudah di-handle oleh method di bawah.

    public function __construct(int $id, string $name, string $value, string $unicode, string $type, string $color)
    {
        $this->id = $id;
        $this->name = $name;
        $this->value = $value;
        $this->unicode = $unicode;
        $this->type = $type;
        $this->color = $color;
    }
    
    // --- BASIC GETTERS ---

    public function getId(): int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getValue(): string { return $this->value; }
    public function getUnicode(): string { return $this->unicode; }
    public function getType(): string { return $this->type; }
    public function getColor(): string { return $this->color; }

    // --- SMART LOGIC ---

    public function isHonor(): bool
    {
        return $this->type === 'honor';
    }

    public function isTerminal(): bool
    {
        // 1. Cek apakah ini batu angka (man, pin, sou)
        $isNumberSuit = in_array($this->type, ['man', 'pin', 'sou']);
        
        // 2. Cek apakah valuenya 1 atau 9
        $isOneOrNine = in_array($this->value, ['1', '9']);

        // Harus keduanya benar (Batu angka DAN nilainya 1 atau 9)
        return $isNumberSuit && $isOneOrNine;
    }

    public function isSimple(): bool
    {
        // 1. Cek apakah ini batu angka
        $isNumberSuit = in_array($this->type, ['man', 'pin', 'sou']);
        
        // 2. Ubah tipe data value menjadi integer (angka) dengan (int) lalu bandingkan
        $numericValue = (int)$this->value;
        $isBetweenTwoAndEight = $numericValue >= 2 && $numericValue <= 8;

        // Harus keduanya benar
        return $isNumberSuit && $isBetweenTwoAndEight;
    }
}