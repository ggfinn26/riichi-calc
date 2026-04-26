<?php

namespace Dewa\Mahjong\Service\Calculation\Score;

use Dewa\Mahjong\Service\Calculation\ValueObject\Payment;
use Dewa\Mahjong\Service\Calculation\ValueObject\ScoringOptions;

/**
 * Konversi basePoints → payment per pemain.
 *
 * Formula (lihat doc §4.3):
 *   ron non-dealer: roundUp100(base × 4)
 *   ron dealer    : roundUp100(base × 6)
 *   tsumo non-dealer: dealer pays roundUp100(base × 2), each non-dealer pays roundUp100(base × 1)
 *   tsumo dealer  : each non-dealer pays roundUp100(base × 2)
 *
 * Honba: +300/honba ke total winner. Ron: dibayar penuh deal-in. Tsumo: 100/honba per pembayar.
 * Riichi sticks: +1000/stick ke total winner.
 *
 * Pao (sekinin barai): jika paoPlayerId di-set, redistribusi.
 */
final class PaymentResolver
{
    public function resolve(
        int $basePoints,
        bool $isDealer,
        bool $isTsumo,
        ScoringOptions $opts,
        bool $paoApplies = false,
    ): array {
        $honba = $opts->honbaCount;
        $sticks = $opts->riichiSticksOnTable;

        if ($isTsumo) {
            $payment = $this->resolveTsumo($basePoints, $isDealer);
        } else {
            $payment = $this->resolveRon($basePoints, $isDealer);
        }

        // Pao yakuman: redistribusi.
        if ($paoApplies) {
            $payment = $this->applyPao($basePoints, $isDealer, $isTsumo, $payment);
        }

        $honbaBonus = $this->honbaBonus($honba, $isTsumo);
        $stickBonus = $sticks * 1000;

        // honba ditambahkan ke total winner; pembagian per-pembayar dikomputasi
        // ulang di applyHonba().
        $payment = $this->applyHonbaToPayment($payment, $honba, $isTsumo, $isDealer);

        return [
            'payment' => $payment,
            'honbaBonus' => $honbaBonus,
            'riichiStickBonus' => $stickBonus,
            'finalTotal' => $payment->total + $stickBonus,
        ];
    }

    private function resolveRon(int $base, bool $isDealer): Payment
    {
        $mult = $isDealer ? 6 : 4;
        return Payment::ron($this->roundUp100($base * $mult));
    }

    private function resolveTsumo(int $base, bool $isDealer): Payment
    {
        if ($isDealer) {
            return Payment::tsumoDealer($this->roundUp100($base * 2));
        }
        return Payment::tsumoNonDealer(
            fromDealer:    $this->roundUp100($base * 2),
            fromNonDealer: $this->roundUp100($base * 1),
        );
    }

    /**
     * Pao redistribusi:
     *  - Tsumo: pao player bayar penuh (sebesar total tsumo non-pao).
     *  - Ron  : pao player & deal-in split 50-50 dari total ron.
     */
    private function applyPao(int $base, bool $isDealer, bool $isTsumo, Payment $original): Payment
    {
        if ($isTsumo) {
            // Pao bayar setara total tsumo. Field fromDealer/fromNonDealer di-zero,
            // pao masuk lewat fromDealInPlayer (kita reuse field itu sebagai "single payer").
            return new Payment(
                fromDealInPlayer: $original->total,
                fromDealer: 0,
                fromNonDealer: 0,
                total: $original->total,
            );
        }
        // Ron: split 50-50, dibulatkan masing-masing ke 100.
        $half = $this->roundUp100(intdiv($original->total, 2));
        return new Payment(
            fromDealInPlayer: $half, // deal-in player
            fromDealer: $half,        // di sini "fromDealer" kita pakai sebagai slot pao share
            fromNonDealer: 0,
            total: $half * 2,
        );
    }

    private function honbaBonus(int $honba, bool $isTsumo): int
    {
        // Total honba bonus yang diterima winner = 300 × honba.
        // (Pembagian per-pembayar diatur di applyHonbaToPayment.)
        return $honba * 300;
    }

    private function applyHonbaToPayment(Payment $p, int $honba, bool $isTsumo, bool $isDealer): Payment
    {
        if ($honba === 0) return $p;

        if (!$isTsumo) {
            // Ron: deal-in bayar penuh + 300 × honba.
            $extra = 300 * $honba;
            return new Payment(
                fromDealInPlayer: $p->fromDealInPlayer + $extra,
                fromDealer: $p->fromDealer,
                fromNonDealer: $p->fromNonDealer,
                total: $p->total + $extra,
            );
        }

        // Tsumo: 100 × honba per pembayar (3 pembayar).
        $perPayer = 100 * $honba;
        return new Payment(
            fromDealInPlayer: $p->fromDealInPlayer,
            fromDealer: $p->fromDealer + ($isDealer ? 0 : $perPayer),
            fromNonDealer: $p->fromNonDealer + $perPayer,
            total: $p->total + 3 * $perPayer,
        );
    }

    private function roundUp100(int $x): int
    {
        if ($x <= 0) return 0;
        return (int) (ceil($x / 100) * 100);
    }
}
