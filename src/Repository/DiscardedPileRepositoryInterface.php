<?php

namespace Dewa\Mahjong\Repository;

use Dewa\Mahjong\Entity\DiscardedPile;

interface DiscardedPileRepositoryInterface
{
    public function findById(int $id): ?DiscardedPile;

    /** @return DiscardedPile[] */
    public function findByGameContextId(int $gameContextId): array;

    /** @return DiscardedPile[] */
    public function findByGameContextAndUser(int $gameContextId, int $userId): array;

    public function findRiichiDeclareByHand(int $gameContextId, int $userId): ?DiscardedPile;

    public function save(DiscardedPile $discard): DiscardedPile;

    public function countByGameContext(int $gameContextId): int;
}
