<?php

namespace Dewa\Mahjong\Repository;

use Dewa\Mahjong\Entity\Hand;
use Dewa\Mahjong\Entity\Tile;

class HandRepository implements HandRepositoryInterface
{
    private \PDO $pdo;
    private MeldRepositoryInterface $meldRepo;

    public function __construct(\PDO $pdo, MeldRepositoryInterface $meldRepo)
    {
        $this->pdo = $pdo;
        $this->meldRepo = $meldRepo;
    }

    // =========================================================================
    // FINDERS
    // =========================================================================

    public function findById(int $id): ?Hand
    {
        $sql = "SELECT * FROM hands WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->hydrateWithRelations($row);
    }

    /**
     * Batch fetch optimal untuk seluruh hand pada 1 ronde:
     *   - 1 query: semua row hands
     *   - 1 query: semua hand_tiles (JOIN tiles) — IN clause
     *   - 1 call:  meldRepo->findByGameContextIdGroupedByHand()
     *
     * Tidak ada N+1: distribusi tiles & melds dilakukan di memori PHP.
     *
     * @return Hand[]
     */
    public function findByGameContextId(int $gameContextId): array
    {
        $sql = "SELECT * FROM hands WHERE game_context_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$gameContextId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return [];
        }

        /** @var array<int, Hand> $handsById */
        $handsById = [];
        foreach ($rows as $row) {
            $hand = $this->hydrate($row);
            $handsById[$hand->getId()] = $hand;
        }

        $this->batchAttachTiles($handsById);

        $meldsByHand = $this->meldRepo->findByGameContextIdGroupedByHand($gameContextId);
        foreach ($meldsByHand as $handId => $melds) {
            if (!isset($handsById[$handId])) {
                continue;
            }
            foreach ($melds as $meld) {
                $handsById[$handId]->addMeld($meld);
            }
        }

        return array_values($handsById);
    }

    public function findByGameContextAndUser(int $gameContextId, int $userId): ?Hand
    {
        $sql = "SELECT * FROM hands WHERE game_context_id = ? AND user_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$gameContextId, $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->hydrateWithRelations($row);
    }

    // =========================================================================
    // WRITERS
    // =========================================================================

    public function save(Hand $hand): Hand
    {
        // Bungkus row utama + sinkronisasi hand_tiles dalam satu transaksi
        // agar tidak terjadi state setengah jadi (DELETE sukses, INSERT gagal).
        $alreadyInTransaction = $this->pdo->inTransaction();
        if (!$alreadyInTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            if ($hand->getId() === 0) {
                $sql = "INSERT INTO hands
                        (game_context_id, user_id, is_dealer, is_riichi_declared,
                         riichi_discard_action_id, nagashi_mangan_discard_id)
                        VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    $hand->getGameContextId(),
                    $hand->getUserId(),
                    (int) $hand->isDealer(),
                    (int) $hand->isRiichiDeclared(),
                    $hand->getRiichiDiscardId(),
                    $hand->getNagashiManganDiscardId(),
                ]);

                $newId = (int) $this->pdo->lastInsertId();
                $this->syncHandTiles($newId, $hand->getTiles());

                $persisted = new Hand(
                    $newId,
                    $hand->getGameContextId(),
                    $hand->getUserId(),
                    $hand->isDealer()
                );
                $persisted->setRiichiDeclared($hand->isRiichiDeclared(), $hand->getRiichiDiscardId());
                $persisted->setNagashiManganDiscardId($hand->getNagashiManganDiscardId());
                foreach ($hand->getTiles() as $tile) {
                    $persisted->addTile($tile);
                }
                foreach ($hand->getMelds() as $meld) {
                    $persisted->addMeld($meld);
                }

                if (!$alreadyInTransaction) {
                    $this->pdo->commit();
                }
                return $persisted;
            }

            $sql = "UPDATE hands
                    SET game_context_id = ?, user_id = ?, is_dealer = ?, is_riichi_declared = ?,
                        riichi_discard_action_id = ?, nagashi_mangan_discard_id = ?
                    WHERE id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $hand->getGameContextId(),
                $hand->getUserId(),
                (int) $hand->isDealer(),
                (int) $hand->isRiichiDeclared(),
                $hand->getRiichiDiscardId(),
                $hand->getNagashiManganDiscardId(),
                $hand->getId(),
            ]);

            $this->syncHandTiles($hand->getId(), $hand->getTiles());

            if (!$alreadyInTransaction) {
                $this->pdo->commit();
            }
            return $hand;
        } catch (\Throwable $e) {
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param Tile[] $tiles
     */
    public function replaceTiles(int $handId, array $tiles): void
    {
        $alreadyInTransaction = $this->pdo->inTransaction();
        if (!$alreadyInTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $this->syncHandTiles($handId, $tiles);
            if (!$alreadyInTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function setRiichiDiscard(int $handId, int $discardActionId): void
    {
        $sql = "UPDATE hands SET is_riichi_declared = 1, riichi_discard_action_id = ? WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$discardActionId, $handId]);
    }

    public function setNagashiManganDiscard(int $handId, int $discardActionId): void
    {
        $sql = "UPDATE hands SET nagashi_mangan_discard_id = ? WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$discardActionId, $handId]);
    }

    // =========================================================================
    // HELPERS (private)
    // =========================================================================

    /**
     * Replace isi hand_tiles untuk satu hand_id sesuai snapshot baru.
     *
     * @param Tile[] $tiles
     */
    private function syncHandTiles(int $handId, array $tiles): void
    {
        $del = $this->pdo->prepare("DELETE FROM hand_tiles WHERE hand_id = ?");
        $del->execute([$handId]);

        if (empty($tiles)) {
            return;
        }

        $ins = $this->pdo->prepare(
            "INSERT INTO hand_tiles (hand_id, tile_id, order_index) VALUES (?, ?, ?)"
        );
        $orderIndex = 0;
        foreach ($tiles as $tile) {
            $ins->execute([$handId, $tile->getId(), $orderIndex]);
            $orderIndex++;
        }
    }

    private function hydrateWithRelations(array $row): Hand
    {
        $hand = $this->hydrate($row);
        $this->attachTiles($hand);
        foreach ($this->meldRepo->findByHandId($hand->getId()) as $meld) {
            $hand->addMeld($meld);
        }
        return $hand;
    }

    private function hydrate(array $row): Hand
    {
        $hand = new Hand(
            (int) $row['id'],
            (int) $row['game_context_id'],
            (int) $row['user_id'],
            (bool) $row['is_dealer']
        );
        $hand->setRiichiDeclared(
            (bool) $row['is_riichi_declared'],
            $row['riichi_discard_action_id'] !== null ? (int) $row['riichi_discard_action_id'] : null
        );
        $hand->setNagashiManganDiscardId(
            $row['nagashi_mangan_discard_id'] !== null ? (int) $row['nagashi_mangan_discard_id'] : null
        );
        return $hand;
    }

    private function attachTiles(Hand $hand): void
    {
        $sql = "SELECT t.id, t.name, t.value, t.unicode, t.type, t.color
                FROM hand_tiles ht
                INNER JOIN tiles t ON t.id = ht.tile_id
                WHERE ht.hand_id = ?
                ORDER BY ht.order_index";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$hand->getId()]);

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $hand->addTile(new Tile(
                (int) $row['id'],
                $row['name'],
                $row['value'],
                $row['unicode'],
                $row['type'],
                $row['color']
            ));
        }
    }

    /**
     * Ambil seluruh hand_tiles untuk banyak Hand sekaligus dalam 1 query (IN clause)
     * lalu distribusikan ke tiap Hand di memori. Tidak ada query per-hand.
     *
     * @param array<int, Hand> $handsById keyed by hand_id
     */
    private function batchAttachTiles(array $handsById): void
    {
        if (empty($handsById)) {
            return;
        }

        $handIds = array_keys($handsById);
        $placeholders = implode(',', array_fill(0, count($handIds), '?'));

        $sql = "SELECT ht.hand_id, t.id, t.name, t.value, t.unicode, t.type, t.color
                FROM hand_tiles ht
                INNER JOIN tiles t ON t.id = ht.tile_id
                WHERE ht.hand_id IN ($placeholders)
                ORDER BY ht.hand_id, ht.order_index";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($handIds);

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $handId = (int) $row['hand_id'];
            if (!isset($handsById[$handId])) {
                continue;
            }
            $handsById[$handId]->addTile(new Tile(
                (int) $row['id'],
                $row['name'],
                $row['value'],
                $row['unicode'],
                $row['type'],
                $row['color']
            ));
        }
    }
}
