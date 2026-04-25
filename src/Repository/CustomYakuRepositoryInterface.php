<?php

namespace Dewa\Mahjong\Repository;

use Dewa\Mahjong\Entity\CustomYaku;

interface CustomYakuRepositoryInterface
{
    public function findById(int $id): ?CustomYaku;
    public function findByCreatedByUserId(int $userId): array;
    public function findByGameContextId(int $gameContextId): array;
    public function save(CustomYaku $customYaku): CustomYaku;
    public function delete(int $id): void;
}