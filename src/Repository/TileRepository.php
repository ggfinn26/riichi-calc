<?php

namespace Dewa\Mahjong\Repository;

use Dewa\Mahjong\Entity\Tile;

class TileRepository implements TileRepositoryInterface
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * FUNGSI HYDRATION (Rahasia Dapur Repository)
     * Mengubah baris array dari MySQL menjadi objek Entitas Tile
     */
    private function hydrate(array $row): Tile
    {
        return new Tile(
            (int) $row['id'],
            $row['name'],
            $row['value'],
            $row['unicode'],
            $row['type'],
            $row['color']
        );
    }

    /**
     * Mencari satu batu spesifik berdasarkan ID-nya (1-34)
     */
    public function findById(int $id): ?Tile
    {
        $sql = "SELECT * FROM tiles WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(1, $id, \PDO::PARAM_INT); // Perbaikan index menjadi 1
        $stmt->execute();
        
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        // Jika tidak ditemukan, return null. Jika ketemu, ubah jadi objek Tile.
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Mencari satu batu spesifik berdasarkan namanya (misal: '1-man', 'chun')
     */
    public function findByName(string $name): ?Tile
    {
        $sql = "SELECT * FROM tiles WHERE name = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(1, $name, \PDO::PARAM_STR);
        $stmt->execute();
        
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Mengambil seluruh 34 batu (biasanya untuk inisialisasi awal atau cache)
     * @return Tile[]
     */
    public function findAll(): array
    {
        $sql = "SELECT * FROM tiles";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Looping semua baris dari database, ubah menjadi array of Objects
        $tiles = [];
        foreach ($rows as $row) {
            $tiles[] = $this->hydrate($row);
        }
        return $tiles;
    }

    /**
     * Mengambil sekumpulan batu berdasarkan tipe (misal: 'man', 'pin', 'sou', 'honor')
     * @return Tile[]
     */
    public function findByType(string $type): array
    {
        $sql = "SELECT * FROM tiles WHERE type = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(1, $type, \PDO::PARAM_STR);
        $stmt->execute();
        
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $tiles = [];
        foreach ($rows as $row) {
            $tiles[] = $this->hydrate($row);
        }
        return $tiles;
    }

    /**
     * Mengambil sekumpulan batu berdasarkan label warna visualnya
     * @return Tile[]
     */
    public function findByColor(string $color): array
    {
        $sql = "SELECT * FROM tiles WHERE color = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(1, $color, \PDO::PARAM_STR);
        $stmt->execute();
        
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $tiles = [];
        foreach ($rows as $row) {
            $tiles[] = $this->hydrate($row);
        }
        return $tiles;
    }
}