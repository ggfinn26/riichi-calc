<?php

namespace Dewa\Mahjong\Repository;

use Dewa\Mahjong\Entity\GameContext;

class GameContextRepository implements GameContextRepositoryInterface{

    private \PDO $pdo;
    public function __construct(\PDO $pdo){
        $this->pdo = $pdo;
    }

    private function hydrate(array $row){
        return new GameContext($row);
    }
    public function findById(int $id): ?GameContext;
    public function findByGameId(int $id): ?GameContext;
    public function findByActiveGameId(int $id): ?GameContext;
    public function save(GameContext $gameContext): GameContext;
    public function addDoraIndicator(int $gameContextId, int $tileId, int $orderIndex): void;
    public function attachCustomYaku(int $gameContextId, int $customYakuId): void;
    public function detachCustomYaku(int $gameContextId, int $customYakuId): void;
    public function markFinished(int $id, string $status): void;
    
}