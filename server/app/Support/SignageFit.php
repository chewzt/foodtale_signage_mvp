<?php

namespace App\Support;

class SignageFit
{
    public static function durationMs(string $fit, ?int $fileMs, int $slotMs): int
    {
        return match ($fit) {
            'once' => $fileMs ?? $slotMs,
            'loop', 'cut' => $slotMs,
            default => $slotMs,
        };
    }
}
