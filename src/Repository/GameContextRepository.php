<?php

namespace Dewa\Mahjong\Repository;

use Dewa\Mahjong\Entity\GameContext;
use Dewa\Mahjong\Entity\Tile;

class GameContextRepository implements GameContextRepositoryInterface
{
    private \PDO $pdo;
    private CustomYakuRepositoryInterface $customYakuRepo;
    private HandRepositoryInterface $handRepo;
    private DiscardedPileRepositoryInterface $discardRepo;

    public function __construct(
        \PDO $pdo,
        CustomYakuRepositoryInterface $customYakuRepo,
        HandRepositoryInterface $handRepo,
        DiscardedPileRepositoryInterface $discardRepo
    ) {
        $this->pdo = $pdo;
        $this->customYakuRepo = $customYakuRepo;
        $this->handRepo = $handRepo;
        $this->discardRepo = $discardRepo;
    }

    // =========================================================================
    // FINDERS
    // =========================================================================

    public function findById(int $id): ?GameContext
    {
        $sql = "SELECT gc.*, g.is_aka_ari
                FROM game_contexts gc
                INNER JOIN games g ON g.id = gc.game_id
                WHERE gc.id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return $this->deepHydrate($this->hydrate($row));
    }

    public function findByActiveGameId(int $gameId): ?GameContext
    {
        $sql = "SELECT gc.*, g.is_aka_ari
                FROM game_contexts gc
                INNER JOIN games g ON g.id = gc.game_id
                WHERE gc.game_id = ? AND gc.status = 'active'";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$gameId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return $this->deepHydrate($this->hydrate($row));
    }

    public function findAllRoundsByGameId(int $gameId): array
    {
        $sql = "SELECT gc.*, g.is_aka_ari
                FROM game_contexts gc
                INNER JOIN games g ON g.id = gc.game_id
                WHERE gc.game_id = ?
                ORDER BY gc.round_number";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$gameId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $rounds = [];
        foreach ($rows as $row) {
            $rounds[] = $this->hydrate($row);
        }
        return $rounds;
    }

    // =========================================================================
    // WRITERS
    // =========================================================================

    /**
     * Aggregate Root save: persist kolom skalar meja + cascade ke child repos
     * (hands, discard pile) di dalam satu transaksi.
     *
     * Anak-anak di-relink ke parent ID yang final sebelum di-save sehingga
     * GameContext yang baru dibuat (id=0) bisa langsung dipersist beserta
     * relasinya tanpa caller perlu memanggil masing-masing repo manual.
     *
     * Atomicity dijamin: kalau cascade hand/discard meledak, INSERT/UPDATE
     * meja juga rollback agar tidak ada state setengah jadi.
     */
    public function save(GameContext $gameContext): GameContext
    {
        $alreadyInTransaction = $this->pdo->inTransaction();
        if (!$alreadyInTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            if ($gameContext->getId() === 0) {
                $sql = "INSERT INTO game_contexts
                        (game_id, round_number, round_wind, dealer_id, current_turn_user_id,
                         status, honba, riichi_sticks, kan_count, left_wall_tiles, next_turn_order_index)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    $gameContext->getGameId(),
                    $gameContext->getRoundNumber(),
                    $gameContext->getRoundWind(),
                    $gameContext->getDealerId(),
                    $gameContext->getCurrentTurnUserId(),
                    $gameContext->getStatus(),
                    $gameContext->getHonba(),
                    $gameContext->getRiichiSticks(),
                    $gameContext->getKanCount(),
                    $gameContext->getLeftWallTiles(),
                    $gameContext->getNextTurnOrderIndex(),
                ]);

                // Promote in-place: relations yang sudah dirakit (hands, discards,
                // dora) tidak hilang, dan caller terus pegang referensi yang sama.
                $gameContext->setId((int) $this->pdo->lastInsertId());
            } else {
                $sql = "UPDATE game_contexts
                        SET game_id = ?, round_number = ?, round_wind = ?, dealer_id = ?, current_turn_user_id = ?,
                            status = ?, honba = ?, riichi_sticks = ?, kan_count = ?, left_wall_tiles = ?, next_turn_order_index = ?
                        WHERE id = ?";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    $gameContext->getGameId(),
                    $gameContext->getRoundNumber(),
                    $gameContext->getRoundWind(),
                    $gameContext->getDealerId(),
                    $gameContext->getCurrentTurnUserId(),
                    $gameContext->getStatus(),
                    $gameContext->getHonba(),
                    $gameContext->getRiichiSticks(),
                    $gameContext->getKanCount(),
                    $gameContext->getLeftWallTiles(),
                    $gameContext->getNextTurnOrderIndex(),
                    $gameContext->getId(),
                ]);
            }

            $this->cascadeSaveHands($gameContext);
            $this->cascadeSaveDiscards($gameContext);

            if (!$alreadyInTransaction) {
                $this->pdo->commit();
            }

            return $gameContext;
        } catch (\Throwable $e) {
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Sinkronkan parent ID dulu, lalu delegasikan persistence + sync hand_tiles
     * ke HandRepository. HandRepository.save() sudah idempotent untuk both
     * INSERT (id=0) dan UPDATE.
     */
    private function cascadeSaveHands(GameContext $gameContext): void
    {
        foreach ($gameContext->getHands() as $hand) {
            if ($hand->getGameContextId() !== $gameContext->getId()) {
                $hand->setGameContextId($gameContext->getId());
            }

            $persisted = $this->handRepo->save($hand);

            // Promote in-place jika repo mengembalikan instance baru pasca-INSERT,
            // sehingga collection di GameContext tetap konsisten dengan DB.
            if ($hand->getId() === 0 && $persisted->getId() !== 0) {
                $hand->setId($persisted->getId());
            }
        }
    }

    /**
     * Hanya INSERT discard yang masih transient (id=0). Discard yang sudah
     * tersimpan tidak disentuh di sini — modifikasinya (mis. set riichi flag)
     * masuk lewat method khusus, bukan re-save penuh.
     */
    private function cascadeSaveDiscards(GameContext $gameContext): void
    {
        foreach ($gameContext->getDiscardPile() as $discard) {
            if ($discard->getId() !== 0) {
                continue;
            }

            if ($discard->getGameContextId() !== $gameContext->getId()) {
                $discard->setGameContextId($gameContext->getId());
            }

            $persisted = $this->discardRepo->save($discard);
            if ($persisted->getId() !== 0) {
                $discard->setId($persisted->getId());
            }
        }
    }

    public function addDoraIndicator(int $gameContextId, int $tileId, int $orderIndex): void
    {
        $sql = "INSERT INTO game_context_dora_indicators (game_context_id, tile_id, order_index)
                VALUES (?, ?, ?)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$gameContextId, $tileId, $orderIndex]);
    }

    public function attachCustomYaku(int $gameContextId, int $customYakuId): void
    {
        $sql = "INSERT INTO game_context_custom_yakus (game_context_id, custom_yaku_id)
                VALUES (?, ?)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$gameContextId, $customYakuId]);
    }

    public function detachCustomYaku(int $gameContextId, int $customYakuId): void
    {
        $sql = "DELETE FROM game_context_custom_yakus
                WHERE game_context_id = ? AND custom_yaku_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$gameContextId, $customYakuId]);
    }

    public function markFinished(int $id, string $status): void
    {
        $sql = "UPDATE game_contexts SET status = ? WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$status, $id]);
    }

    // =========================================================================
    // HYDRATION (private)
    // =========================================================================

    private function hydrate(array $row): GameContext
    {
        return new GameContext(
            (int) $row["id"],
            (int) $row["game_id"],
            (int) $row["round_number"],
            $row["round_wind"],
            (int) $row["dealer_id"],
            (int) $row["current_turn_user_id"],
            $row["status"],
            (int) $row["honba"],
            (int) $row["riichi_sticks"],
            (int) $row["kan_count"],
            (int) $row["left_wall_tiles"],
            (int) $row["next_turn_order_index"],
            (bool) $row["is_aka_ari"]
        );
    }

    /**
     * Rakit semua relasi: dora, hands (+tiles+melds), discards, custom yakus.
     *
     * Setiap sub-collection didelegasikan ke repository ahlinya:
     *   - hands (+ hand_tiles + melds + meld_tiles): HandRepository (batch internal)
     *   - discard pile: DiscardedPileRepository
     *   - custom yaku aktif: CustomYakuRepository
     *
     * Hanya dora indicator yang masih dihidrasi inline karena belum punya repo
     * tersendiri (data sangat sederhana: tile_id + order_index).
     */
    private function deepHydrate(GameContext $gc): GameContext
    {
        $this->hydrateDora($gc);

        foreach ($this->handRepo->findByGameContextId($gc->getId()) as $hand) {
            $gc->addHand($hand);
        }

        foreach ($this->discardRepo->findByGameContextId($gc->getId()) as $discard) {
            $gc->restoreDiscard($discard);
        }

        foreach ($this->customYakuRepo->findByGameContextId($gc->getId()) as $yaku) {
            $gc->addActiveCustomYaku($yaku);
        }

        return $gc;
    }

    private function hydrateDora(GameContext $gc): void
    {
        $sql = "SELECT t.id, t.name, t.value, t.unicode, t.type, t.color
                FROM game_context_dora_indicators gcdi
                INNER JOIN tiles t ON t.id = gcdi.tile_id
                WHERE gcdi.game_context_id = ?
                ORDER BY gcdi.order_index";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$gc->getId()]);

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $gc->addDoraIndicator(new Tile(
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
