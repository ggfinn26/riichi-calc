<?php

namespace Dewa\Mahjong\Service\Calculation\ValueObject;

/**
 * Detail pembayaran. Untuk ron, fromDealInPlayer terisi dan fromDealer/
 * fromNonDealer = 0. Untuk tsumo, fromDealInPlayer = 0 dan dua field
 * lainnya terisi (fromDealer 0 jika winner adalah dealer).
 */
final class Payment
{
    public function __construct(
        public readonly int $fromDealInPlayer,
        public readonly int $fromDealer,
        public readonly int $fromNonDealer,
        public readonly int $total,
    ) {}

    public static function ron(int $amount): self
    {
        return new self($amount, 0, 0, $amount);
    }

    public static function tsumoNonDealer(int $fromDealer, int $fromNonDealer): self
    {
        return new self(0, $fromDealer, $fromNonDealer, $fromDealer + 2 * $fromNonDealer);
    }

    public static function tsumoDealer(int $fromEachNonDealer): self
    {
        // Dealer tsumo: 3 non-dealer bayar masing-masing fromEachNonDealer.
        return new self(0, 0, $fromEachNonDealer, 3 * $fromEachNonDealer);
    }
}
