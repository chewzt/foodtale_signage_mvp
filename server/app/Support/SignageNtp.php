<?php

namespace App\Support;

class SignageNtp
{
    public static function unixMicroseconds(): int
    {
        [$frac, $sec] = explode(' ', microtime(), 2);

        return ((int) $sec * 1_000_000) + (int) round(((float) $frac) * 1_000_000);
    }

    /**
     * @return array{utc: string, t1: int, t2: int, t0?: int}
     */
    public static function payload(mixed $t0): array
    {
        $t1 = self::unixMicroseconds();
        $body = [
            'utc' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            't1' => $t1,
            't2' => self::unixMicroseconds(),
        ];

        if (is_numeric($t0)) {
            $body['t0'] = (int) $t0;
        }

        return $body;
    }
}
