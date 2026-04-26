<?php

namespace Dewa\Mahjong\Repository;

use Dewa\Mahjong\Entity\DiscardedPile;
use Dewa\Mahjong\Entity\Tile;

class DiscardedPileRepository implements DiscardedPileRepositoryInterface
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // =========================================================================
    // FINDERS
    // =========================================================================

    public function findById(int $id): ?DiscardedPile
    {
        $sql = $this->baseSelect() . " WHERE da.id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    /** @return DiscardedPile[] */
    public function findByGameContextId(int $gameContextId): array
    {
        $sql = $this->baseSelect() . " WHERE da.game_context_id = ? ORDER BY da.order_index";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$gameContextId]);

        return $this->hydrateMany($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return DiscardedPile[] */
    public function findByGameContextAndUser(int $gameContextId, int $userId): array
    {
        $sql = $this->baseSelect()
            . " WHERE da.game_context_id = ? AND da.user_id = ? ORDER BY da.order_index";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$gameContextId, $userId]);

        return $this->hydrateMany($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function findRiichiDeclareByHand(int $gameContextId, int $userId): ?DiscardedPile
    {
        $sql = $this->baseSelect()
            . " WHERE da.game_context_id = ? AND da.user_id = ? AND da.is_riichi_declare = 1
                LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$gameContextId, $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    // =========================================================================
    // WRITERS
    // =========================================================================

    public function save(DiscardedPile $discard): DiscardedPile
    {
        if ($discard->getId() === 0) {
            $sql = "INSERT INTO discard_actions
                    (game_context_id, user_id, tile_id, turn_order,
                     is_riichi_declare, is_tsumogiri, order_index)
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $discard->getGameContextId(),
                $discard->getUserId(),
                $discard->getTile()->getId(),
                $discard->getTurnOrder(),
                (int) $discard->isRiichiDeclare(),
                (int) $discard->isTsumo(),
                $discard->getOrderIndex(),
            ]);

            $newId = (int) $this->pdo->lastInsertId();

            return new DiscardedPile(
                $newId,
                $discard->getGameContextId(),
                $discard->getUserId(),
                $discard->getTile(),
                $discard->getTurnOrder(),
                $discard->isRiichiDeclare(),
                $discard->isTsumo(),
                $discard->getOrderIndex()
            );
        }

        $sql = "UPDATE discard_actions
                SET game_context_id = ?, user_id = ?, tile_id = ?, turn_order = ?,
                    is_riichi_declare = ?, is_tsumogiri = ?, order_index = ?
                WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $discard->getGameContextId(),
            $discard->getUserId(),
            $discard->getTile()->getId(),
            $discard->getTurnOrder(),
            (int) $discard->isRiichiDeclare(),
            (int) $discard->isTsumo(),
            $discard->getOrderIndex(),
            $discard->getId(),
        ]);

        return $discard;
    }

    public function countByGameContext(int $gameContextId): int
    {
        $sql = "SELECT COUNT(*) FROM discard_actions WHERE game_context_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$gameContextId]);

        return (int) $stmt->fetchColumn();
    }

    // =========================================================================
    // HYDRATION (private)
    // =========================================================================

    private function baseSelect(): string
    {
        return "SELECT da.id, da.game_context_id, da.user_id, da.turn_order,
                       da.is_riichi_declare, da.is_tsumogiri, da.order_index,
                       t.id AS t_id, t.name AS t_name, t.value AS t_value,
                       t.unicode AS t_unicode, t.type AS t_type, t.color AS t_color
                FROM discard_actions da
                INNER JOIN tiles t ON t.id = da.tile_id";
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return DiscardedPile[]
     */
    private function hydrateMany(array $rows): array
    {
        $discards = [];
        foreach ($rows as $row) {
            $discards[] = $this->hydrate($row);
        }
        return $discards;
    }

    private function hydrate(array $row): DiscardedPile
    {
        $tile = new Tile(
            (int) $row['t_id'],
            $row['t_name'],
            $row['t_value'],
            $row['t_unicode'],
            $row['t_type'],
            $row['t_color']
        );

        return new DiscardedPile(
            (int) $row['id'],
            (int) $row['game_context_id'],
            (int) $row['user_id'],
            $tile,
            (int) $row['turn_order'],
            (bool) $row['is_riichi_declare'],
            (bool) $row['is_tsumogiri'],
            (int) $row['order_index']
        );
    }
}
