<?php

namespace Dewa\Mahjong\Repository;

use Dewa\Mahjong\Entity\Meld;
use Dewa\Mahjong\Entity\Tile;

class MeldRepository implements MeldRepositoryInterface
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // =========================================================================
    // FINDERS
    // =========================================================================

    public function findById(int $id): ?Meld
    {
        $sql = "SELECT * FROM melds WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $tiles = $this->fetchMeldTiles([(int) $row['id']]);
        return $this->hydrate($row, $tiles[(int) $row['id']] ?? []);
    }

    /** @return Meld[] */
    public function findByHandId(int $handId): array
    {
        $sql = "SELECT * FROM melds WHERE hand_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$handId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $this->hydrateMany($rows);
    }

    /** @return Meld[] */
    public function findByGameContextId(int $gameContextId): array
    {
        $sql = "SELECT * FROM melds WHERE game_context_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$gameContextId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $this->hydrateMany($rows);
    }

    /**
     * Batch fetch optimal:
     *   - 1 query untuk semua row meld pada ronde ini
     *   - 1 query (IN clause) untuk semua meld_tiles + JOIN tiles
     * Lalu hasilnya dikelompokkan per hand_id di memori PHP.
     *
     * @return array<int, Meld[]> map hand_id => Meld[]
     */
    public function findByGameContextIdGroupedByHand(int $gameContextId): array
    {
        $sql = "SELECT * FROM melds WHERE game_context_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$gameContextId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return [];
        }

        $meldIds = [];
        foreach ($rows as $row) {
            $meldIds[] = (int) $row['id'];
        }
        $tilesByMeld = $this->fetchMeldTiles($meldIds);

        $byHand = [];
        foreach ($rows as $row) {
            $handId = (int) $row['hand_id'];
            $byHand[$handId][] = $this->hydrate($row, $tilesByMeld[(int) $row['id']] ?? []);
        }
        return $byHand;
    }

    /** @return Meld[] */
    public function findByUserId(int $userId): array
    {
        $sql = "SELECT * FROM melds WHERE user_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $this->hydrateMany($rows);
    }

    // =========================================================================
    // WRITERS
    // =========================================================================

    public function save(Meld $meld): Meld
    {
        $alreadyInTransaction = $this->pdo->inTransaction();
        if (!$alreadyInTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            if ($meld->getId() === 0) {
                $sql = "INSERT INTO melds (game_context_id, user_id, hand_id, type, is_closed)
                        VALUES (?, ?, ?, ?, ?)";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    $meld->getGameContextId(),
                    $meld->getUserId(),
                    $meld->getHandId(),
                    $meld->getType(),
                    (int) $meld->isClosed(),
                ]);

                $newId = (int) $this->pdo->lastInsertId();
                $this->syncMeldTiles($newId, $meld->getTiles());

                $persisted = new Meld(
                    $newId,
                    $meld->getGameContextId(),
                    $meld->getUserId(),
                    $meld->getHandId(),
                    $meld->getType(),
                    $meld->isClosed(),
                    $meld->getTiles()
                );

                if (!$alreadyInTransaction) {
                    $this->pdo->commit();
                }
                return $persisted;
            }

            $sql = "UPDATE melds
                    SET game_context_id = ?, user_id = ?, hand_id = ?, type = ?, is_closed = ?
                    WHERE id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $meld->getGameContextId(),
                $meld->getUserId(),
                $meld->getHandId(),
                $meld->getType(),
                (int) $meld->isClosed(),
                $meld->getId(),
            ]);

            $this->syncMeldTiles($meld->getId(), $meld->getTiles());

            if (!$alreadyInTransaction) {
                $this->pdo->commit();
            }
            return $meld;
        } catch (\Throwable $e) {
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function delete(int $id): void
    {
        // ON DELETE CASCADE pada meld_tiles akan menghapus child secara otomatis
        $sql = "DELETE FROM melds WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
    }

    // =========================================================================
    // HELPERS (private)
    // =========================================================================

    /**
     * Replace seluruh isi meld_tiles untuk satu meld_id dengan urutan order_index baru.
     *
     * @param Tile[] $tiles
     */
    private function syncMeldTiles(int $meldId, array $tiles): void
    {
        $del = $this->pdo->prepare("DELETE FROM meld_tiles WHERE meld_id = ?");
        $del->execute([$meldId]);

        if (empty($tiles)) {
            return;
        }

        $ins = $this->pdo->prepare(
            "INSERT INTO meld_tiles (meld_id, tile_id, order_index) VALUES (?, ?, ?)"
        );
        $orderIndex = 0;
        foreach ($tiles as $tile) {
            $ins->execute([$meldId, $tile->getId(), $orderIndex]);
            $orderIndex++;
        }
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return Meld[]
     */
    private function hydrateMany(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $meldIds = [];
        foreach ($rows as $row) {
            $meldIds[] = (int) $row['id'];
        }
        $tilesByMeld = $this->fetchMeldTiles($meldIds);

        $melds = [];
        foreach ($rows as $row) {
            $melds[] = $this->hydrate($row, $tilesByMeld[(int) $row['id']] ?? []);
        }
        return $melds;
    }

    /**
     * @param int[] $meldIds
     * @return array<int, Tile[]>
     */
    private function fetchMeldTiles(array $meldIds): array
    {
        if (empty($meldIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($meldIds), '?'));
        $sql = "SELECT mt.meld_id, t.id, t.name, t.value, t.unicode, t.type, t.color
                FROM meld_tiles mt
                INNER JOIN tiles t ON t.id = mt.tile_id
                WHERE mt.meld_id IN ($placeholders)
                ORDER BY mt.meld_id, mt.order_index";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($meldIds);

        $tilesByMeld = [];
        foreach ($meldIds as $id) {
            $tilesByMeld[$id] = [];
        }
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $tilesByMeld[(int) $row['meld_id']][] = new Tile(
                (int) $row['id'],
                $row['name'],
                $row['value'],
                $row['unicode'],
                $row['type'],
                $row['color']
            );
        }
        return $tilesByMeld;
    }

    /**
     * @param Tile[] $tiles
     */
    private function hydrate(array $row, array $tiles): Meld
    {
        return new Meld(
            (int) $row['id'],
            (int) $row['game_context_id'],
            (int) $row['user_id'],
            (int) $row['hand_id'],
            $row['type'],
            (bool) $row['is_closed'],
            $tiles
        );
    }
}
