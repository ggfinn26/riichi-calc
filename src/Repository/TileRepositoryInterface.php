<?php

namespace Dewa\Mahjong\Repository;

use Dewa\Mahjong\Entity\Tile;

interface TileRepositoryInterface
{
    /**
     * Mencari satu batu spesifik berdasarkan ID-nya (1-34)
     */
    public function findById(int $id): ?Tile;

    /**
     * Mencari satu batu spesifik berdasarkan namanya (misal: '1-man', 'chun')
     */
    public function findByName(string $name): ?Tile;

    /**
     * Mengambil seluruh 34 batu (biasanya untuk inisialisasi awal atau cache)
     * * @return Tile[]
     */
    public function findAll(): array;

    /**
     * Mengambil sekumpulan batu berdasarkan tipe (misal: 'man', 'pin', 'sou', 'honor')
     * Sangat berguna untuk YakuEvaluator saat mengecek Chinitsu/Honitsu
     * * @return Tile[]
     */
    public function findByType(string $type): array;

    /**
     * Mengambil sekumpulan batu yang memiliki warna tertentu
     * * @return Tile[]
     */
    public function findByColor(string $color): array;
}