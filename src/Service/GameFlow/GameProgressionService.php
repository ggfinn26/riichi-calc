<?php
declare(strict_types=1);

namespace Dewa\Mahjong\Service\GameFlow;

use Dewa\Mahjong\Entity\GameContext;
use Dewa\Mahjong\Entity\Hand;
use Dewa\Mahjong\Repository\GameContextRepositoryInterface;
use Dewa\Mahjong\Service\Calculation\WaitCalculator;

/**
 * GameProgressionService
 * -----------------------------------------------------------------------------
 * Manages the lifecycle of a Mahjong game: resolution of a round's end state
 * (Agari / Ryuukyoku / Chombo / Suukaikan), dealer rotation, honba progression,
 * and initialisation of the following round's GameContext.
 *
 * Round-end kinds handled:
 *   - "agari"       : a player won (tsumo or ron). Dealer keeps seat (Renchan)
 *                     only if dealer was the winner.
 *   - "ryuukyoku"   : wall exhausted. Compute No-ten Bappu (3000 pts divided)
 *                     between tenpai and noten players. Dealer keeps seat iff
 *                     dealer is tenpai (Tenpai Renchan).
 *   - "chombo"      : rule violation. Penalty is not computed here (varies by
 *                     ruleset); dealer rotation logic still invoked.
 *   - "suukaikan"   : 4 kan abort draw. Same seat progression as ryuukyoku
 *                     without bappu (rule variant: no bappu).
 */
final class GameProgressionService
{
    public const END_AGARI     = 'agari';
    public const END_RYUUKYOKU = 'ryuukyoku';
    public const END_CHOMBO    = 'chombo';
    public const END_SUUKAIKAN = 'suukaikan';

    public function __construct(
        private readonly GameContextRepositoryInterface $gameContextRepo,
        private readonly WaitCalculator $waitCalculator,
    ) {}

    /**
     * Resolve how a round ended and initialise the next GameContext.
     *
     * @param int    $gameContextId ID of the just-finished round
     * @param string $endType       one of END_* constants
     * @param int|null $winnerUserId (optional) required for END_AGARI
     *
     * @return array{
     *     notenBappu: array<int,int>,  // userId => payment (positive = gained, negative = paid)
     *     nextContextId: int,
     *     dealerRetained: bool,
     *     honba: int
     * }
     */
    public function resolveRoundEnd(int $gameContextId, string $endType, ?int $winnerUserId = null): array
    {
        $context = $this->gameContextRepo->findById($gameContextId);
        if ($context === null) {
            throw new \RuntimeException("GameContext #{$gameContextId} not found.");
        }

        $notenBappu     = [];
        $dealerRetained = false;

        switch ($endType) {
            case self::END_AGARI:
                if ($winnerUserId === null) {
                    throw new \InvalidArgumentException("Winner userId required for agari.");
                }
                $dealerRetained = ($winnerUserId === $context->getDealerId());
                break;

            case self::END_RYUUKYOKU:
                $notenBappu     = $this->calculateNotenBappu($context);
                $dealerRetained = $this->isDealerTenpai($context);
                break;

            case self::END_CHOMBO:
                // Chombo: dealer keeps seat in most rulesets; honba typically does NOT increase.
                $dealerRetained = true; // reseat; penalty handled elsewhere
                break;

            case self::END_SUUKAIKAN:
                // Abort draw — dealer keeps seat, honba +1, no bappu.
                $dealerRetained = true;
                break;

            default:
                throw new \InvalidArgumentException("Unknown end type: {$endType}");
        }

        // Mark the current round as finished so it will not be picked up by
        // findByActiveGameId() on the next query.
        $this->gameContextRepo->markFinished($gameContextId, 'finished');

        // Build and persist the next round.
        $next = $this->initializeNextRound($context, $dealerRetained, $endType);

        return [
            'notenBappu'     => $notenBappu,
            'nextContextId'  => $next->getId(),
            'dealerRetained' => $dealerRetained,
            'honba'          => $next->getHonba(),
        ];
    }

    /**
     * Compute No-ten Bappu: 3000 points is transferred from noten players to
     * tenpai players following the standard distribution table:
     *   1 tenpai vs 3 noten  → tenpai +3000, each noten −1000
     *   2 tenpai vs 2 noten  → each tenpai +1500, each noten −1500
     *   3 tenpai vs 1 noten  → each tenpai +1000, the noten −3000
     *   0 or 4 tenpai        → no transfer
     *
     * @return array<int,int> userId => signed points (positive = receive, negative = pay)
     */
    private function calculateNotenBappu(GameContext $context): array
    {
        $tenpaiUsers = [];
        $notenUsers  = [];

        foreach ($context->getHands() as $hand) {
            if ($this->isHandTenpai($hand)) {
                $tenpaiUsers[] = $hand->getUserId();
            } else {
                $notenUsers[]  = $hand->getUserId();
            }
        }

        $tCount = count($tenpaiUsers);
        $nCount = count($notenUsers);

        if ($tCount === 0 || $nCount === 0) {
            return []; // no transfer
        }

        $pool       = 3000;
        $perTenpai  = intdiv($pool, $tCount);
        $perNoten   = -intdiv($pool, $nCount);

        $bappu = [];
        foreach ($tenpaiUsers as $uid) $bappu[$uid] = $perTenpai;
        foreach ($notenUsers  as $uid) $bappu[$uid] = $perNoten;
        return $bappu;
    }

    private function isDealerTenpai(GameContext $context): bool
    {
        foreach ($context->getHands() as $hand) {
            if ($hand->isDealer()) {
                return $this->isHandTenpai($hand);
            }
        }
        return false;
    }

    private function isHandTenpai(Hand $hand): bool
    {
        // A hand is tenpai iff its waits set is non-empty.
        return $this->waitCalculator->calculateWaits($hand) !== [];
    }

    /**
     * Create and persist the GameContext for the next round.
     */
    private function initializeNextRound(GameContext $prev, bool $dealerRetained, string $endType): GameContext
    {
        // Honba rules:
        //   - Dealer renchan (win or draw w/ tenpai) → honba + 1
        //   - Dealer loses / non-dealer wins          → honba reset to 0
        //   - Chombo                                  → honba unchanged (most rules)
        //   - Suukaikan abort                         → honba + 1
        $honba = $prev->getHonba();
        if ($endType === self::END_CHOMBO) {
            // unchanged
        } elseif ($dealerRetained) {
            $honba += 1;
        } else {
            $honba = 0;
        }

        // Round/wind progression: only shifts when dealer does NOT retain.
        $nextRoundNumber = $prev->getRoundNumber();
        $nextRoundWind   = $prev->getRoundWind();
        if (!$dealerRetained) {
            $nextRoundNumber++;
            if ($nextRoundNumber > 4) {
                // Round wind rolls: east → south (→ west → north in longer games)
                $nextRoundNumber = 1;
                $nextRoundWind   = $this->nextWind($prev->getRoundWind());
            }
        }

        // Dealer rotation.
        $nextDealerId = $dealerRetained
            ? $prev->getDealerId()
            : $this->nextPlayerUserId($prev, $prev->getDealerId());

        // Riichi sticks are carried over on draw / chombo, collected by winner on agari.
        $nextRiichiSticks = $endType === self::END_AGARI ? 0 : $prev->getRiichiSticks();

        $next = new GameContext(
            id: 0,
            gameId: $prev->getGameId(),
            roundNumber: $nextRoundNumber,
            roundWind: $nextRoundWind,
            dealerId: $nextDealerId,
            currentTurnUserId: $nextDealerId,
            status: 'active',
            honba: $honba,
            riichiSticks: $nextRiichiSticks,
            kanCount: 0,
            leftWallTiles: 70,
            nextTurnOrderIndex: 1,
            isAkaAri: $prev->isAkaAri(),
        );

        return $this->gameContextRepo->save($next);
    }

    private function nextWind(string $wind): string
    {
        return match ($wind) {
            'east'  => 'south',
            'south' => 'west',
            'west'  => 'north',
            'north' => 'east',
            default => 'east',
        };
    }

    /**
     * Find the user immediately after $currentUserId in seating order.
     * We don't have explicit seating numbers on Hand, so we infer by userId
     * ascending within the 4 seats loaded into the context.
     */
    private function nextPlayerUserId(GameContext $context, int $currentUserId): int
    {
        $userIds = array_keys($context->getHands());
        sort($userIds);
        if ($userIds === []) return $currentUserId;

        $idx = array_search($currentUserId, $userIds, true);
        if ($idx === false) return $userIds[0];
        return $userIds[($idx + 1) % count($userIds)];
    }
}
