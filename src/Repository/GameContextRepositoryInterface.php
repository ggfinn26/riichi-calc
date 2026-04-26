<?php

namespace Dewa\Mahjong\Repository;

use Dewa\Mahjong\Entity\GameContext;

interface GameContextRepositoryInterface
{
    /**
     * Mengambil satu ronde lengkap dengan deep hydration:
     * dora indicators, hands (+ tiles + melds), discard pile, dan custom yaku aktif.
     */
    public function findById(int $id): ?GameContext;

    /**
     * Mengambil semua ronde dalam satu game (Timur 1, Timur 2, Selatan 1, dst).
     * Shallow hydration — hanya kolom skalar. Untuk rekap skor/list view.
     *
     * @return GameContext[]
     */
    public function findAllRoundsByGameId(int $gameId): array;

    /**
     * Mengambil ronde yang sedang berjalan untuk sebuah game.
     * Deep hydration — siap dipakai oleh evaluator.
     */
    public function findByActiveGameId(int $gameId): ?GameContext;

    public function save(GameContext $gameContext): GameContext;
    public function addDoraIndicator(int $gameContextId, int $tileId, int $orderIndex): void;
    public function attachCustomYaku(int $gameContextId, int $customYakuId): void;
    public function detachCustomYaku(int $gameContextId, int $customYakuId): void;
    public function markFinished(int $id, string $status): void;
}
