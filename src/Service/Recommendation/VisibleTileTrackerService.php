<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Service\Recommendation;

use Dewa\Mahjong\Entity\GameContext;
use Dewa\Mahjong\Repository\TileRepositoryInterface;

/**
 * VisibleTileTrackerService
 * -----------------------------------------------------------------------------
 * Counts how many copies of each of the 34 tile kinds are still unseen from
 * the perspective of the player using this calculator. "Unseen" means the
 * tile is neither in the discard pile, an open meld on the table, nor already
 * revealed as a dora indicator.
 *
 * Important: Tiles in OTHER players' closed hands are still "unseen" from the
 * calculator's viewpoint — they could still be drawn. The four-copy limit per
 * tile kind gives us a hard upper bound; this service just subtracts what has
 * been seen publicly.
 *
 * For own hand, the caller can further subtract held tiles before displaying
 * results to the user.
 */
final class VisibleTileTrackerService
{
    public function __construct(
        private readonly TileRepositoryInterface $tileRepo,
    ) {}

    /**
     * @return array<int,int> tileId => number of copies still possibly out there (0..4)
     */
    public function getRemainingTilesProbabilities(GameContext $context): array
    {
        // Start from 4 copies for every tile kind.
        $remaining = [];
        foreach ($this->tileRepo->findAll() as $tile) {
            $remaining[$tile->getId()] = 4;
        }

        // 1. Subtract the discard pile (public information).
        foreach ($context->getDiscardPile() as $discard) {
            $id = $discard->getTile()->getId();
            if (isset($remaining[$id])) {
                $remaining[$id] = max(0, $remaining[$id] - 1);
            }
        }

        // 2. Subtract tiles in open melds of every hand (chi / pon / daiminkan /
        //    shouminkan — ankan is technically concealed but the tile kind is
        //    revealed when declared, so it still counts as visible).
        foreach ($context->getHands() as $hand) {
            foreach ($hand->getMelds() as $meld) {
                foreach ($meld->getTiles() as $tile) {
                    $id = $tile->getId();
                    if (isset($remaining[$id])) {
                        $remaining[$id] = max(0, $remaining[$id] - 1);
                    }
                }
            }
        }

        // 3. Subtract dora indicators.
        foreach ($context->getDoraIndicators() as $tile) {
            $id = $tile->getId();
            if (isset($remaining[$id])) {
                $remaining[$id] = max(0, $remaining[$id] - 1);
            }
        }

        return $remaining;
    }

    /**
     * Convenience: count remaining copies for a single tile id, also
     * subtracting the caller's own hand tiles (to report truly unseen tiles
     * from the calculator user's perspective).
     *
     * @param int[] $ownHandTileIds
     */
    public function countRemainingForTile(int $tileId, GameContext $context, array $ownHandTileIds = []): int
    {
        $map = $this->getRemainingTilesProbabilities($context);
        $base = $map[$tileId] ?? 0;

        $ownCount = 0;
        foreach ($ownHandTileIds as $id) {
            if ($id === $tileId) $ownCount++;
        }

        return max(0, $base - $ownCount);
    }
}
