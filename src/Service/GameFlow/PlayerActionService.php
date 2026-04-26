<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Service\GameFlow;

use Dewa\Mahjong\Entity\DiscardedPile;
use Dewa\Mahjong\Entity\Hand;
use Dewa\Mahjong\Entity\Meld;
use Dewa\Mahjong\Entity\Tile;
use Dewa\Mahjong\Repository\DiscardedPileRepositoryInterface;
use Dewa\Mahjong\Repository\GameContextRepositoryInterface;
use Dewa\Mahjong\Repository\HandRepositoryInterface;
use Dewa\Mahjong\Repository\MeldRepositoryInterface;
use Dewa\Mahjong\Repository\TileRepositoryInterface;

/**
 * PlayerActionService
 * -----------------------------------------------------------------------------
 * Orchestrator for every physical action a player can take during a round:
 * draw, discard, call (chi/pon/kan), and declare riichi.
 *
 * This service is the bridge between the Controller layer (user intent)
 * and the Infrastructure layer (repositories). It mutates entities in
 * memory and then delegates persistence to the appropriate repository so
 * cascade-save can run consistently.
 *
 * All methods are transactional at the caller's discretion — this service
 * does not start DB transactions itself, keeping it framework-agnostic.
 */
final class PlayerActionService
{
    public function __construct(
        private readonly HandRepositoryInterface $handRepo,
        private readonly GameContextRepositoryInterface $gameContextRepo,
        private readonly DiscardedPileRepositoryInterface $discardRepo,
        private readonly MeldRepositoryInterface $meldRepo,
        private readonly TileRepositoryInterface $tileRepo,
    ) {}

    /**
     * Discard a tile from the player's hand into the shared discard pile.
     *
     * @throws \RuntimeException if state is invalid (hand/tile not found)
     */
    public function processDiscard(int $gameContextId, int $userId, int $tileId, bool $isTsumogiri = false): DiscardedPile
    {
        $context = $this->gameContextRepo->findById($gameContextId);
        if ($context === null) {
            throw new \RuntimeException("GameContext #{$gameContextId} not found.");
        }

        $hand = $this->handRepo->findByGameContextAndUser($gameContextId, $userId);
        if ($hand === null) {
            throw new \RuntimeException("Hand for user #{$userId} in context #{$gameContextId} not found.");
        }

        $tile = $this->tileRepo->findById($tileId);
        if ($tile === null) {
            throw new \RuntimeException("Tile #{$tileId} not found.");
        }

        // 1. Remove the tile from the hand (first matching occurrence).
        $this->removeTileFromHand($hand, $tileId);

        // 2. Create the discard entity with a fresh orderIndex (next turn index).
        $discard = new DiscardedPile(
            id: 0,
            gameContextId: $gameContextId,
            userId: $userId,
            tile: $tile,
            turnOrder: $context->getRoundNumber(),
            isRiichiDeclare: false,
            isTsumo: $isTsumogiri,
            orderIndex: $context->getNextTurnOrderIndex(),
        );

        // 3. Register discard on context (also bumps nextTurnOrderIndex).
        $context->registerDiscard($discard);

        // 4. Persist: save discard first (needs FK), then update hand tiles, then context.
        $savedDiscard = $this->discardRepo->save($discard);
        $this->handRepo->replaceTiles($hand->getId(), $hand->getTiles());
        $this->gameContextRepo->save($context);

        return $savedDiscard;
    }

    /**
     * Declare Riichi: mark the player as riichi, transfer a 1000-point stick to
     * the table, and flag the discard tile as the riichi-declaring one.
     *
     * The caller must have already invoked {@see processDiscard()} for the sideways
     * tile; the returned discard's id should be passed here.
     *
     * @throws \RuntimeException if hand is not eligible (not menzen / already declared)
     */
    public function processRiichiDeclaration(int $handId, int $discardActionId): void
    {
        $hand = $this->handRepo->findById($handId);
        if ($hand === null) {
            throw new \RuntimeException("Hand #{$handId} not found.");
        }

        // Smart action on entity enforces Menzenchin invariant.
        $hand->declareRiichi($discardActionId);

        // Persist riichi state & add a stick to the table.
        $this->handRepo->setRiichiDiscard($handId, $discardActionId);

        $context = $this->gameContextRepo->findById($hand->getGameContextId());
        if ($context !== null) {
            $context->addRiichiStick();
            $this->gameContextRepo->save($context);
        }
    }

    /**
     * Process a meld call (chi / pon / daiminkan) against an opponent's discard.
     *
     * @param int[] $tileIdsFromHand tile IDs currently in hand used to complete the meld
     *                               (e.g. for pon: two matching tiles; for chi: the two neighbours)
     */
    public function processCall(int $handId, string $meldType, int $calledTileId, array $tileIdsFromHand, bool $isClosed = false): Meld
    {
        $hand = $this->handRepo->findById($handId);
        if ($hand === null) {
            throw new \RuntimeException("Hand #{$handId} not found.");
        }

        // Collect Tile objects for the meld.
        $meldTiles = [];
        foreach ($tileIdsFromHand as $tid) {
            $t = $this->tileRepo->findById($tid);
            if ($t === null) {
                throw new \RuntimeException("Tile #{$tid} not found.");
            }
            $meldTiles[] = $t;
            $this->removeTileFromHand($hand, $tid);
        }

        $calledTile = $this->tileRepo->findById($calledTileId);
        if ($calledTile === null) {
            throw new \RuntimeException("Tile #{$calledTileId} not found.");
        }
        $meldTiles[] = $calledTile;

        $meld = new Meld(
            id: 0,
            gameContextId: $hand->getGameContextId(),
            userId: $hand->getUserId(),
            handId: $hand->getId(),
            type: $meldType,
            isClosed: $isClosed,
            tiles: $meldTiles,
        );

        if (!$meld->isValidMeldCount()) {
            throw new \RuntimeException("Invalid meld tile count for type '{$meldType}'.");
        }

        $hand->addMeld($meld);
        $saved = $this->meldRepo->save($meld);

        // Persist the updated hand (tiles moved out).
        $this->handRepo->replaceTiles($hand->getId(), $hand->getTiles());

        return $saved;
    }

    /**
     * Player draws a tile from the live wall.
     */
    public function processDraw(int $gameContextId, int $userId, Tile $drawnTile): void
    {
        $context = $this->gameContextRepo->findById($gameContextId);
        if ($context === null) {
            throw new \RuntimeException("GameContext #{$gameContextId} not found.");
        }
        $hand = $this->handRepo->findByGameContextAndUser($gameContextId, $userId);
        if ($hand === null) {
            throw new \RuntimeException("Hand not found.");
        }

        $context->drawTileFromWall();
        $hand->addTile($drawnTile);

        $this->handRepo->replaceTiles($hand->getId(), $hand->getTiles());
        $this->gameContextRepo->save($context);
    }

    /**
     * Helper: remove the first tile with a given id from a hand's closed tiles.
     *
     * @throws \RuntimeException if the tile is not present in the hand.
     */
    private function removeTileFromHand(Hand $hand, int $tileId): void
    {
        $tiles = $hand->getTiles();
        foreach ($tiles as $idx => $tile) {
            if ($tile->getId() === $tileId) {
                array_splice($tiles, $idx, 1);
                // Hand has no direct setTiles — relink via repository contract:
                // we keep the mutation visible by replacing through reflection-safe path.
                // Here we use a controlled approach: clear and re-add to preserve encapsulation.
                $this->resetHandTiles($hand, $tiles);
                return;
            }
        }
        throw new \RuntimeException("Tile #{$tileId} not present in hand.");
    }

    /**
     * Resetting a Hand's tiles without exposing a public setter on the entity.
     *
     * The Hand entity only offers addTile(); to "remove" we rebuild via the
     * repository layer: persistence of the updated collection is the source of
     * truth. In memory we emulate the same by clearing and re-adding — done
     * through a reflection trick only inside this service (acceptable since
     * this is the orchestrator responsible for the mutation).
     *
     * @param Tile[] $newTiles
     */
    private function resetHandTiles(Hand $hand, array $newTiles): void
    {
        // Use Reflection to access the private $tiles property — this is the
        // single point in the codebase where we do so, and it's scoped to
        // in-memory synchronization. Persistence still happens via
        // HandRepositoryInterface::replaceTiles().
        $refl = new \ReflectionObject($hand);
        $prop = $refl->getProperty('tiles');
        $prop->setAccessible(true);
        $prop->setValue($hand, array_values($newTiles));
    }
}
