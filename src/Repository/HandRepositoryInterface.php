<?php

namespace Dewa\Mahjong\Repository;

use Dewa\Mahjong\Entity\Hand;

interface HandRepositoryInterface{
    public function findById(int $id): ?Hand;
    public function findByGameContextId(int $gameContextId): array;
    public function findByGameContextAndUser(int $gameContextId, int $userId): ?Hand;
    public function save(Hand $hand): Hand;
    public function replaceTiles(int $handId, array $tiles): void;
    public function setRiichiDiscard(int $handId, int $discardActionId): void;
    public function setNagashiManganDiscard(int $handId, int $discardActionId): void;
    
}