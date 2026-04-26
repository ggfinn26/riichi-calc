<?php

namespace Dewa\Mahjong\Service\Calculation\ValueObject;

final class WaitType
{
    public const RYANMEN = 'ryanmen';
    public const KANCHAN = 'kanchan';
    public const PENCHAN = 'penchan';
    public const TANKI   = 'tanki';
    public const SHANPON = 'shanpon';

    public static function fuFor(string $type): int
    {
        return match ($type) {
            self::KANCHAN, self::PENCHAN, self::TANKI => 2,
            default => 0,
        };
    }
}
