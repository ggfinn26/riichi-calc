<?php

namespace Dewa\Mahjong\Repository;

use Dewa\Mahjong\Entity\Yaku;

class YakuRepository implements YakuRepositoryInterface
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // Tambahkan return type : Yaku agar lebih strict
    private function hydrate(array $row): Yaku
    {
        return new Yaku(
            (int) $row["id"],
            $row["name_jp"],
            $row["name_eng"],
            $row["description"],
            (int) $row["han_closed"],
            (int) $row["han_opened"],
            (bool) $row["is_yakuman"],
            $row["conditions"] ?? null
        );
    }

    public function findById(int $id): ?Yaku
    {
        $sql = "SELECT * FROM yakus WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        // Menggunakan bindValue lebih direkomendasikan untuk tipe data skalar di PDO
        $stmt->bindValue(1, $id, \PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return $row ? $this->hydrate($row) : null;
    }

    public function findByNameJp(string $nameJp): ?Yaku
    {
        $sql = "SELECT * FROM yakus WHERE name_jp = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(1, $nameJp, \PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return $row ? $this->hydrate($row) : null;
    }

    public function findByNameEng(string $nameEng): ?Yaku
    {
        $sql = "SELECT * FROM yakus WHERE name_eng = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(1, $nameEng, \PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * @return Yaku[]
     */
    public function findAll(): array
    {
        $sql = "SELECT * FROM yakus";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $yakus = [];
        foreach ($rows as $row) {
            $yakus[] = $this->hydrate($row);
        }
        return $yakus;
    }

    /**
     * @return Yaku[]
     */
    public function findClosedOnly(): array
    {
        // LOGIKA MAHJONG: han_opened = 0 (tidak sah jika terbuka), tapi han_closed > 0
        $sql = "SELECT * FROM yakus WHERE han_opened = 0 AND han_closed > 0";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $yakus = [];
        foreach ($rows as $row) {
            $yakus[] = $this->hydrate($row);
        }
        return $yakus;
    }
}