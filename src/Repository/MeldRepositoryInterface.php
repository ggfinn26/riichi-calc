<?php

namespace Dewa\Mahjong\Repository;

use Dewa\Mahjong\Entity\Meld;

interface MeldRepositoryInterface
{
    public function findById(int $id): ?Meld;

    /** @return Meld[] */
    public function findByHandId(int $handId): array;

    /** @return Meld[] */
    public function findByGameContextId(int $gameContextId): array;

    /**
     * Batch fetch: ambil semua meld dalam 1 ronde lalu kelompokkan per hand_id.
     * Dipakai HandRepository untuk menghindari N+1 saat hidrasi banyak Hand.
     *
     * @return array<int, Meld[]> map hand_id => Meld[]
     */
    public function findByGameContextIdGroupedByHand(int $gameContextId): array;

    /** @return Meld[] */
    public function findByUserId(int $userId): array;

    public function save(Meld $meld): Meld;

    public function delete(int $id): void;
}
