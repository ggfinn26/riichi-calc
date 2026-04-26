<?php

namespace Dewa\Mahjong\Service\Calculation\ValueObject;

/**
 * Satu cara mendekomposisi 14 tile ke (4 set + 1 pair) ATAU 7 pair (chiitoitsu)
 * ATAU pola kokushi. Dipakai untuk evaluasi fu dan yaku per cabang.
 */
final class Decomposition
{
    public const SHAPE_STANDARD   = 'standard';
    public const SHAPE_CHIITOITSU = 'chiitoitsu';
    public const SHAPE_KOKUSHI    = 'kokushi';

    /**
     * @param SetGroup[] $groups urutan: untuk standard = [pair, set, set, set, set] (4 set + 1 pair, urutan bebas)
     * @param string $waitType lihat WaitType
     * @param int $waitGroupIndex index group yang menjadi "wait" (group yang diselesaikan winning tile)
     */
    public function __construct(
        public readonly string $shape,
        public readonly array $groups,
        public readonly string $waitType,
        public readonly int $waitGroupIndex,
    ) {}

    /** @return SetGroup[] */
    public function getGroups(): array { return $this->groups; }

    public function getPair(): ?SetGroup
    {
        foreach ($this->groups as $g) {
            if ($g->isPair()) return $g;
        }
        return null;
    }

    /** @return SetGroup[] */
    public function getSets(): array
    {
        return array_values(array_filter($this->groups, fn(SetGroup $g) => !$g->isPair()));
    }
}
