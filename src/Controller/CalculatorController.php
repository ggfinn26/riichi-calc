<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Controller;

use Dewa\Mahjong\DTO\CalculateScoreRequest;
use Dewa\Mahjong\DTO\CallActionRequest;
use Dewa\Mahjong\DTO\DefenseEvaluationRequest;
use Dewa\Mahjong\DTO\DiscardActionRequest;
use Dewa\Mahjong\DTO\DiscardRecommendationRequest;
use Dewa\Mahjong\DTO\DrawActionRequest;
use Dewa\Mahjong\DTO\EvaluateYakuRequest;
use Dewa\Mahjong\DTO\ResolveRoundRequest;
use Dewa\Mahjong\DTO\RiichiActionRequest;
use Dewa\Mahjong\DTO\ShantenRequest;
use Dewa\Mahjong\DTO\StartGameRequest;
use Dewa\Mahjong\DTO\WaitsRequest;
use Dewa\Mahjong\Entity\GameContext;
use Dewa\Mahjong\Http\Request;
use Dewa\Mahjong\Http\Response;
use Dewa\Mahjong\Repository\GameContextRepositoryInterface;
use Dewa\Mahjong\Repository\HandRepositoryInterface;
use Dewa\Mahjong\Repository\TileRepositoryInterface;
use Dewa\Mahjong\Repository\YakuRepositoryInterface;
use Dewa\Mahjong\Repository\CustomYakuRepositoryInterface;
use Dewa\Mahjong\Repository\MeldRepositoryInterface;
use Dewa\Mahjong\Repository\DiscardedPileRepositoryInterface;
use Dewa\Mahjong\Service\Calculation\ScoringService;
use Dewa\Mahjong\Service\Calculation\WinningHandInput;
use Dewa\Mahjong\Service\Calculation\YakuEvaluator;
use Dewa\Mahjong\Service\Calculation\FuritenChecker;
use Dewa\Mahjong\Service\Calculation\WaitCalculator;
use Dewa\Mahjong\Service\Calculation\ValueObject\ScoringOptions;
use Dewa\Mahjong\Service\GameFlow\PlayerActionService;
use Dewa\Mahjong\Service\GameFlow\GameProgressionService;
use Dewa\Mahjong\Service\Recommendation\DefenseEvaluatorService;
use Dewa\Mahjong\Service\Recommendation\DiscardRecommendationService;
use Dewa\Mahjong\Service\Recommendation\ShantenCalculator;
use Psr\Log\LoggerInterface;

/**
 * CalculatorController
 * -----------------------------------------------------------------------
 * Thin HTTP adapter. Parses DTO from Request, delegates to Service,
 * serialises result via Response.
 *
 * All public methods follow this signature:
 *   public function methodName(Request $request [, string $id]): void
 *
 * Route → Method mapping is defined in config/routes.php.
 */
final class CalculatorController
{
    public function __construct(
        // --- Repositories ---
        private readonly GameContextRepositoryInterface   $gameContextRepo,
        private readonly HandRepositoryInterface          $handRepo,
        private readonly TileRepositoryInterface          $tileRepo,
        private readonly YakuRepositoryInterface          $yakuRepo,
        private readonly CustomYakuRepositoryInterface    $customYakuRepo,
        private readonly MeldRepositoryInterface          $meldRepo,
        private readonly DiscardedPileRepositoryInterface $discardRepo,

        // --- Services ---
        private readonly ScoringService                $scoringService,
        private readonly YakuEvaluator                 $yakuEvaluator,
        private readonly FuritenChecker                $furitenChecker,
        private readonly WaitCalculator                $waitCalculator,
        private readonly ShantenCalculator             $shantenCalculator,
        private readonly DiscardRecommendationService  $discardRecommendation,
        private readonly DefenseEvaluatorService       $defenseEvaluator,
        private readonly PlayerActionService           $playerActionService,
        private readonly GameProgressionService        $gameProgressionService,

        // --- Logger ---
        private readonly LoggerInterface $logger,
    ) {}

    // =========================================================================
    // SCORE / YAKU
    // =========================================================================

    /**
     * POST /api/score/calculate
     */
    public function calculateScore(Request $request): void
    {
        $dto = CalculateScoreRequest::fromArray($request->getBody());

        $context = $this->gameContextRepo->findById($dto->gameContextId)
            ?? throw new \RuntimeException("GameContext #{$dto->gameContextId} not found.");

        $hand = $this->handRepo->findById($dto->handId)
            ?? throw new \RuntimeException("Hand #{$dto->handId} not found.");

        $winningTile = $this->tileRepo->findById($dto->winningTileId)
            ?? throw new \RuntimeException("Tile #{$dto->winningTileId} not found.");

        $yakuList = [];
        foreach ($dto->yakuIds as $yakuId) {
            $yakuList[] = $this->yakuRepo->findById($yakuId)
                ?? throw new \RuntimeException("Yaku #{$yakuId} not found.");
        }

        $opts = new ScoringOptions(
            honbaCount:          $dto->honbaCount ?: $context->getHonba(),
            riichiSticksOnTable: $dto->riichiSticks ?: $context->getRiichiSticks(),
            paoPlayerId:         $dto->paoPlayerId,
            paoYakumanType:      $dto->paoYakumanType,
        );

        $input = new WinningHandInput(
            hand:        $hand,
            winningTile: $winningTile,
            isTsumo:     $dto->isTsumo,
            roundWind:   $context->getRoundWind(),
            seatWind:    $this->resolveSeatWind($hand->getUserId(), $context->getDealerId()),
            yakuList:    $yakuList,
            doraCount:   $dto->doraCount,
            isPinfu:     $dto->isPinfu,
        );

        $result = $this->scoringService->calculate($input, $opts);

        $this->logger->info('Score calculated', [
            'hand_id'     => $dto->handId,
            'han'         => $result->han,
            'fu'          => $result->fu,
            'final_total' => $result->finalTotal,
        ]);

        Response::json([
            'han'                => $result->han,
            'fu'                 => $result->fu,
            'fu_breakdown'       => $result->fuBreakdown,
            'yaku_list'          => array_map(fn($y) => [
                'id'       => $y->getId(),
                'name_jp'  => $y->getNameJp(),
                'name_eng' => $y->getNameEng(),
            ], $result->yakuList),
            'base_points'        => $result->basePoints,
            'limit_name'         => $result->limitName,
            'yakuman_multiplier' => $result->yakumanMultiplier,
            'payment' => [
                'from_deal_in'    => $result->payment->fromDealInPlayer,
                'from_dealer'     => $result->payment->fromDealer,
                'from_non_dealer' => $result->payment->fromNonDealer,
                'total'           => $result->payment->total,
            ],
            'honba_bonus'        => $result->honbaBonus,
            'riichi_stick_bonus' => $result->riichiStickBonus,
            'final_total'        => $result->finalTotal,
        ]);
    }

    /**
     * POST /api/score/evaluate-yaku
     */
    public function evaluateYaku(Request $request): void
    {
        $dto = EvaluateYakuRequest::fromArray($request->getBody());

        $context = $this->gameContextRepo->findById($dto->gameContextId)
            ?? throw new \RuntimeException("GameContext #{$dto->gameContextId} not found.");

        $hand = $this->handRepo->findById($dto->handId)
            ?? throw new \RuntimeException("Hand #{$dto->handId} not found.");

        $winningTile = $this->tileRepo->findById($dto->winningTileId)
            ?? throw new \RuntimeException("Tile #{$dto->winningTileId} not found.");

        $result = $this->yakuEvaluator->evaluate(
            $hand,
            $winningTile,
            $context,
            $dto->isTsumo,
            $dto->isRiichi
        );

        Response::json([
            'yakus'     => array_map(fn($y) => [
                'id'         => $y->getId(),
                'name_jp'    => $y->getNameJp(),
                'name_eng'   => $y->getNameEng(),
                'han_closed' => $y->getHanClosed(),
                'han_opened' => $y->getHanOpened(),
                'is_yakuman' => $y->isYakuman(),
            ], $result['yakus']),
            'total_han' => $result['total_han'],
        ]);
    }

    // =========================================================================
    // QUERIES
    // =========================================================================

    /**
     * GET /api/game/{id}
     */
    public function getGameContext(Request $request, string $id): void
    {
        $context = $this->gameContextRepo->findById((int) $id)
            ?? throw new \RuntimeException("GameContext #{$id} not found.");

        Response::json([
            'id'                    => $context->getId(),
            'game_id'               => $context->getGameId(),
            'round_number'          => $context->getRoundNumber(),
            'round_wind'            => $context->getRoundWind(),
            'dealer_id'             => $context->getDealerId(),
            'current_turn_user_id'  => $context->getCurrentTurnUserId(),
            'status'                => $context->getStatus(),
            'honba'                 => $context->getHonba(),
            'riichi_sticks'         => $context->getRiichiSticks(),
            'kan_count'             => $context->getKanCount(),
            'left_wall_tiles'       => $context->getLeftWallTiles(),
            'next_turn_order_index' => $context->getNextTurnOrderIndex(),
            'is_aka_ari'            => $context->isAkaAri(),
            'dora_indicators'       => array_map(fn($t) => [
                'id'      => $t->getId(),
                'name'    => $t->getName(),
                'unicode' => $t->getUnicode(),
                'type'    => $t->getType(),
            ], $context->getDoraIndicators()),
            'hands' => array_map(fn($h) => [
                'id'                 => $h->getId(),
                'user_id'            => $h->getUserId(),
                'is_dealer'          => $h->isDealer(),
                'is_riichi_declared' => $h->isRiichiDeclared(),
                'tile_count'         => $h->getTotalTilesCount(),
            ], $context->getHands()),
        ]);
    }

    /**
     * GET /api/hand/{id}
     */
    public function getHand(Request $request, string $id): void
    {
        $hand = $this->handRepo->findById((int) $id)
            ?? throw new \RuntimeException("Hand #{$id} not found.");

        $tiles = array_map(fn($t) => [
            'id'      => $t->getId(),
            'name'    => $t->getName(),
            'unicode' => $t->getUnicode(),
            'type'    => $t->getType(),
            'value'   => $t->getValue(),
        ], $hand->getTiles());

        $melds = array_map(fn($m) => [
            'id'        => $m->getId(),
            'type'      => $m->getType(),
            'is_closed' => $m->isClosed(),
            'tiles'     => array_map(fn($t) => [
                'id'      => $t->getId(),
                'name'    => $t->getName(),
                'unicode' => $t->getUnicode(),
            ], $m->getTiles()),
        ], $hand->getMelds());

        $waits = $this->waitCalculator->calculateWaits($hand);

        Response::json([
            'id'                   => $hand->getId(),
            'game_context_id'      => $hand->getGameContextId(),
            'user_id'              => $hand->getUserId(),
            'is_dealer'            => $hand->isDealer(),
            'is_menzen'            => $hand->isMenzen(),
            'is_riichi_declared'   => $hand->isRiichiDeclared(),
            'riichi_discard_id'    => $hand->getRiichiDiscardId(),
            'tile_count_logical'   => $hand->getTotalTilesCount(),
            'tile_count_physical'  => $hand->getTotalPhysicalTilesCount(),
            'tiles'                => $tiles,
            'melds'                => $melds,
            'waits'                => array_map(fn($t) => [
                'id'      => $t->getId(),
                'name'    => $t->getName(),
                'unicode' => $t->getUnicode(),
            ], $waits),
        ]);
    }

    // =========================================================================
    // PLAYER ACTIONS
    // =========================================================================

    /**
     * POST /api/action/draw
     */
    public function processDraw(Request $request): void
    {
        $dto  = DrawActionRequest::fromArray($request->getBody());
        $tile = $this->tileRepo->findById($dto->tileId)
            ?? throw new \RuntimeException("Tile #{$dto->tileId} not found.");

        $this->playerActionService->processDraw($dto->gameContextId, $dto->userId, $tile);

        $this->logger->info('Tile drawn', [
            'game_context_id' => $dto->gameContextId,
            'user_id'         => $dto->userId,
            'tile_id'         => $dto->tileId,
        ]);

        Response::json(['success' => true, 'message' => "Tile #{$dto->tileId} drawn by user #{$dto->userId}."]);
    }

    /**
     * POST /api/action/discard
     */
    public function processDiscard(Request $request): void
    {
        $dto     = DiscardActionRequest::fromArray($request->getBody());
        $discard = $this->playerActionService->processDiscard(
            $dto->gameContextId,
            $dto->userId,
            $dto->tileId,
            $dto->isTsumogiri
        );

        $this->logger->info('Tile discarded', [
            'game_context_id' => $dto->gameContextId,
            'user_id'         => $dto->userId,
            'tile_id'         => $dto->tileId,
            'discard_id'      => $discard->getId(),
        ]);

        Response::json([
            'success'     => true,
            'discard_id'  => $discard->getId(),
            'tile_id'     => $discard->getTile()->getId(),
            'order_index' => $discard->getOrderIndex(),
        ]);
    }

    /**
     * POST /api/action/riichi
     */
    public function processRiichi(Request $request): void
    {
        $dto = RiichiActionRequest::fromArray($request->getBody());
        $this->playerActionService->processRiichiDeclaration($dto->handId, $dto->discardActionId);

        $this->logger->info('Riichi declared', ['hand_id' => $dto->handId]);

        Response::json(['success' => true, 'message' => "Riichi declared for hand #{$dto->handId}."]);
    }

    /**
     * POST /api/action/call
     */
    public function processCall(Request $request): void
    {
        $dto  = CallActionRequest::fromArray($request->getBody());
        $meld = $this->playerActionService->processCall(
            $dto->handId,
            $dto->meldType,
            $dto->calledTileId,
            $dto->tileIdsFromHand,
            $dto->isClosed
        );

        $this->logger->info('Meld called', [
            'hand_id'   => $dto->handId,
            'meld_type' => $dto->meldType,
            'meld_id'   => $meld->getId(),
        ]);

        Response::json([
            'success' => true,
            'meld_id' => $meld->getId(),
            'type'    => $meld->getType(),
            'tiles'   => array_map(fn($t) => [
                'id'   => $t->getId(),
                'name' => $t->getName(),
            ], $meld->getTiles()),
        ]);
    }

    // =========================================================================
    // ROUND LIFECYCLE
    // =========================================================================

    /**
     * POST /api/round/resolve
     */
    public function resolveRound(Request $request): void
    {
        $dto    = ResolveRoundRequest::fromArray($request->getBody());
        $result = $this->gameProgressionService->resolveRoundEnd(
            $dto->gameContextId,
            $dto->endType,
            $dto->winnerUserId
        );

        $this->logger->info('Round resolved', [
            'game_context_id' => $dto->gameContextId,
            'end_type'        => $dto->endType,
            'next_context_id' => $result['nextContextId'],
        ]);

        Response::json([
            'success'         => true,
            'next_context_id' => $result['nextContextId'],
            'dealer_retained' => $result['dealerRetained'],
            'honba'           => $result['honba'],
            'noten_bappu'     => $result['notenBappu'],
        ]);
    }

    // =========================================================================
    // ANALYSIS (shanten / waits)
    // =========================================================================

    /**
     * POST /api/score/shanten
     */
    public function calculateShanten(Request $request): void
    {
        $dto  = ShantenRequest::fromArray($request->getBody());
        $hand = $this->handRepo->findById($dto->handId)
            ?? throw new \RuntimeException("Hand #{$dto->handId} not found.");

        $shanten = $this->shantenCalculator->calculate($hand);

        Response::json([
            'hand_id' => $hand->getId(),
            'shanten' => $shanten,
            'state'   => match (true) {
                $shanten <= -1 => 'agari',
                $shanten === 0 => 'tenpai',
                $shanten === 1 => 'iishanten',
                default        => "shanten_{$shanten}",
            },
        ]);
    }

    /**
     * POST /api/score/waits
     */
    public function calculateWaits(Request $request): void
    {
        $dto  = WaitsRequest::fromArray($request->getBody());
        $hand = $this->handRepo->findById($dto->handId)
            ?? throw new \RuntimeException("Hand #{$dto->handId} not found.");

        $waits = $this->waitCalculator->calculateWaits($hand);

        Response::json([
            'hand_id' => $hand->getId(),
            'count'   => count($waits),
            'waits'   => array_map(fn($t) => [
                'id'      => $t->getId(),
                'name'    => $t->getName(),
                'unicode' => $t->getUnicode(),
                'type'    => $t->getType(),
                'value'   => $t->getValue(),
            ], $waits),
        ]);
    }

    // =========================================================================
    // GAME LIFECYCLE
    // =========================================================================

    /**
     * POST /api/game/start
     *
     * Inisialisasi GameContext (ronde) baru untuk game yang sudah ada.
     * Mengembalikan ID konteks baru sehingga client bisa langsung memanggil
     * endpoint action/draw, action/discard, dst.
     */
    public function startGame(Request $request): void
    {
        $dto = StartGameRequest::fromArray($request->getBody());

        $context = new GameContext(
            id:                 0,
            gameId:             $dto->gameId,
            roundNumber:        $dto->roundNumber,
            roundWind:          $dto->roundWind,
            dealerId:           $dto->dealerId,
            currentTurnUserId:  $dto->dealerId,
            status:             'active',
            honba:              0,
            riichiSticks:       0,
            kanCount:           0,
            leftWallTiles:      70,
            nextTurnOrderIndex: 1,
            isAkaAri:           $dto->isAkaAri,
        );

        $saved = $this->gameContextRepo->save($context);

        $this->logger->info('Game started', [
            'game_id'         => $dto->gameId,
            'game_context_id' => $saved->getId(),
            'dealer_id'       => $dto->dealerId,
        ]);

        Response::json([
            'success'         => true,
            'game_context_id' => $saved->getId(),
            'game_id'         => $saved->getGameId(),
            'round_number'    => $saved->getRoundNumber(),
            'round_wind'      => $saved->getRoundWind(),
            'dealer_id'       => $saved->getDealerId(),
            'status'          => $saved->getStatus(),
            'is_aka_ari'      => $saved->isAkaAri(),
        ]);
    }

    // =========================================================================
    // RECOMMENDATIONS
    // =========================================================================

    /**
     * POST /api/recommendation/discard
     */
    public function recommendDiscard(Request $request): void
    {
        $dto = DiscardRecommendationRequest::fromArray($request->getBody());

        $hand = $this->handRepo->findById($dto->handId)
            ?? throw new \RuntimeException("Hand #{$dto->handId} not found.");

        $context = $this->gameContextRepo->findById($dto->gameContextId)
            ?? throw new \RuntimeException("GameContext #{$dto->gameContextId} not found.");

        $recommendations = $this->discardRecommendation->getRecommendations(
            $hand,
            $context,
            $dto->threateningUserIds,
            $dto->weights,
        );

        Response::json([
            'hand_id'         => $hand->getId(),
            'game_context_id' => $context->getId(),
            'count'           => count($recommendations),
            'recommendations' => $recommendations,
        ]);
    }

    /**
     * POST /api/recommendation/defense
     */
    public function evaluateDefense(Request $request): void
    {
        $dto = DefenseEvaluationRequest::fromArray($request->getBody());

        $context = $this->gameContextRepo->findById($dto->gameContextId)
            ?? throw new \RuntimeException("GameContext #{$dto->gameContextId} not found.");

        $evaluations = [];
        foreach ($dto->tileIds as $tileId) {
            $tile = $this->tileRepo->findById($tileId)
                ?? throw new \RuntimeException("Tile #{$tileId} not found.");

            $danger = $this->defenseEvaluator->evaluateDangerLevel(
                $tile,
                $context,
                $dto->targetUserId,
            );

            $evaluations[] = [
                'tile_id'      => $tile->getId(),
                'tile_name'    => $tile->getName(),
                'danger_level' => $danger,
                'safety_score' => max(0.0, 100.0 - $danger),
                'verdict'      => match (true) {
                    $danger <= 5.0  => 'safe',
                    $danger <= 30.0 => 'mostly_safe',
                    $danger <= 55.0 => 'caution',
                    default         => 'dangerous',
                },
            ];
        }

        Response::json([
            'game_context_id' => $context->getId(),
            'target_user_id'  => $dto->targetUserId,
            'evaluations'     => $evaluations,
        ]);
    }

    // =========================================================================
    // UTILITY
    // =========================================================================

    /**
     * GET /api/health
     */
    public function health(Request $request): void
    {
        Response::json([
            'status'    => 'ok',
            'app'       => 'Riichi Mahjong Calculator',
            'timestamp' => date('c'),
        ]);
    }

    // -----------------------------------------------------------------------

    /**
     * Resolve a player's seat wind from their user ID vs the dealer's ID.
     * Dealer is always East. This is a simplification — override by passing
     * seat_wind explicitly in future iterations.
     */
    private function resolveSeatWind(int $userId, int $dealerId): string
    {
        return $userId === $dealerId ? 'east' : 'south';
    }
}
