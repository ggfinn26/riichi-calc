<?php

namespace Dewa\Mahjong\Repository;

use Dewa\Mahjong\Entity\CustomYaku;

class CustomYakuRepository implements CustomYakuRepositoryInterface
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    private function hydrate(array $row): CustomYaku
    {
        // Perbaikan typo nama kolom
        $conditionsArray = json_decode($row["conditions"], true) ?? [];
        
        return new CustomYaku(
            (int) $row["id"], 
            $row["name"],
            $row["description"],
            (int) $row["han_closed"],
            (int) $row["han_opened"],
            (bool) $row["is_yakuman"],
            $conditionsArray,
            new \DateTime($row["created_at"]),
            new \DateTime($row["updated_at"]),
            (int) $row["created_by_user_id"],
            (bool) $row["is_deleted"] // Asumsi Anda sudah menambahkan ini di Entity
        );
    }

    public function findById(int $id): ?CustomYaku
    {
        // Tetap bisa mencari berdasarkan ID meskipun sudah soft-delete (berguna untuk Replay Log)
        $sql = "SELECT * FROM custom_yakus WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return $row ? $this->hydrate($row) : null;
    }

    public function findByCreatedByUserId(int $userId): array
    {
        // Perbaikan: Hanya tampilkan yang belum dihapus (is_deleted = 0)
        $sql = "SELECT * FROM custom_yakus WHERE created_by_user_id = ? AND is_deleted = 0";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $createdByUserId = [];
        foreach ($rows as $row) {
            // Perbaikan penampung array
            $createdByUserId[] = $this->hydrate($row);
        }
        return $createdByUserId;
    }

    public function findByGameContextId(int $gameContextId): array
    {
        $sql = "SELECT cy.* FROM custom_yakus cy
                INNER JOIN game_context_custom_yakus gccy ON cy.id = gccy.custom_yaku_id
                WHERE gccy.game_context_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$gameContextId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $customYaku = [];
        foreach ($rows as $row) {
            $customYaku[] = $this->hydrate($row);
        }
        return $customYaku;
    }

    public function save(CustomYaku $customYaku): CustomYaku
    {
        $conditionJson = json_encode($customYaku->getConditions());
        
        if ($customYaku->getId() === 0) {
            $sql = "INSERT INTO custom_yakus 
                    (name, description, han_closed, han_opened, is_yakuman, conditions, created_at, updated_at, created_by_user_id, is_deleted) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $customYaku->getName(),
                $customYaku->getDescription(),
                $customYaku->getHanClosed(),
                $customYaku->getHanOpened(),
                (int) $customYaku->isYakuman(),
                $conditionJson,
                $customYaku->getCreatedAt()->format('Y-m-d H:i:s'),
                $customYaku->getUpdatedAt()->format('Y-m-d H:i:s'),
                $customYaku->getCreatedByUserId(),
                (int) $customYaku->isDeleted()
            ]);

            $newId = (int) $this->pdo->lastInsertId();
            
            return new CustomYaku(
                $newId,
                $customYaku->getName(),
                $customYaku->getDescription(),
                $customYaku->getHanClosed(),
                $customYaku->getHanOpened(),
                $customYaku->isYakuman(),
                $customYaku->getConditions(),
                $customYaku->getCreatedAt(),
                $customYaku->getUpdatedAt(),
                $customYaku->getCreatedByUserId(),
                $customYaku->isDeleted()
            );
        } else {
            // Perbaikan: Parameter execute disamakan posisinya dengan query SQL
            $sql = "UPDATE custom_yakus 
                    SET name = ?, description = ?, han_closed = ?, han_opened = ?, is_yakuman = ?, conditions = ?, updated_at = ?, is_deleted = ?
                    WHERE id = ?";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $customYaku->getName(),
                $customYaku->getDescription(),
                $customYaku->getHanClosed(),
                $customYaku->getHanOpened(),
                (int) $customYaku->isYakuman(),
                $conditionJson, // Menggunakan string JSON, BUKAN array
                $customYaku->getUpdatedAt()->format("Y-m-d H:i:s"),
                (int) $customYaku->isDeleted(),
                $customYaku->getId() // Posisi WHERE id = ?
            ]);

            return $customYaku;
        }
    }

    public function delete(int $id): void
    {
        // Perbaikan: Query SQL UPDATE yang valid
        $sql = "UPDATE custom_yakus SET is_deleted = 1, updated_at = NOW() WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
    }
}