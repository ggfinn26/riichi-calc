<?php

namespace Dewa\Mahjong\Service\Calculation;

use Dewa\Mahjong\Entity\Yaku;
use Dewa\Mahjong\Service\Calculation\ValueObject\Decomposition;
use Dewa\Mahjong\Service\Calculation\ValueObject\Payment;

/**
 * Hasil akhir scoring. Immutable.
 */
final class ScoreResult
{
    /**
     * @param Yaku[] $yakuList
     * @param array<string,int> $fuBreakdown rincian fu (label => poin)
     */
    public function __construct(
        public readonly int $han,
        public readonly int $fu,
        public readonly array $fuBreakdown,
        public readonly array $yakuList,
        public readonly int $basePoints,
        public readonly ?string $limitName, // 'mangan' | 'haneman' | 'baiman' | 'sanbaiman' | 'yakuman' | null
        public readonly int $yakumanMultiplier, // 0 jika bukan yakuman; 1, 2, ... untuk yakuman/double-yakuman/dst
        public readonly Payment $payment,
        public readonly int $honbaBonus,
        public readonly int $riichiStickBonus,
        public readonly int $finalTotal,
        public readonly ?Decomposition $decomposition,
    ) {}

    public function isLimitHand(): bool
    {
        return $this->limitName !== null;
    }
}
