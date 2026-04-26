<?php

namespace Dewa\Mahjong\Service\Calculation\ValueObject;

use Dewa\Mahjong\Entity\Tile;

/**
 * Hasil dekomposisi: satu kelompok (set/pair/quad) dengan flag concealed.
 * Dipakai oleh FuCalculator dan YakuEvaluator.
 *
 * Concealed di sini berarti "tertutup secara logis untuk fu". Triplet yang
 * diselesaikan lewat ron diperlakukan open meskipun tile-nya dari tangan
 * (lihat §6 doc + spec).
 */
final class SetGroup
{
    public const KIND_PAIR    = 'pair';
    public const KIND_CHI     = 'chi';     // run / sequence
    public const KIND_KOUTSU  = 'koutsu';  // triplet
    public const KIND_KANTSU  = 'kantsu';  // quad

    /**
     * @param Tile[] $tiles
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $tiles,
        public readonly bool $isConcealed,
    ) {}

    public function isPair(): bool   { return $this->kind === self::KIND_PAIR; }
    public function isChi(): bool    { return $this->kind === self::KIND_CHI; }
    public function isKoutsu(): bool { return $this->kind === self::KIND_KOUTSU; }
    public function isKantsu(): bool { return $this->kind === self::KIND_KANTSU; }

    /**
     * Cek apakah kelompok ini punya tile terminal/honor (untuk fu yaochuu).
     */
    public function hasTerminalOrHonor(): bool
    {
        foreach ($this->tiles as $t) {
            if ($t->isHonor() || $t->isTerminal()) {
                return true;
            }
        }
        return false;
    }

    public function representativeTile(): Tile
    {
        return $this->tiles[0];
    }
}
