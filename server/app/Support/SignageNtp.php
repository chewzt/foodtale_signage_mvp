<?php

namespace App\Support;

class SignageNtp
{
    /**
     * Unix microseconds as a JSON number.
     * 32-bit PHP cannot store these in int (PHP_INT_MAX is 2.1e9).
     */
    public static function unixMicroseconds(): float
    {
        [$frac, $sec] = explode(' ', microtime(), 2);

        return ((float) $sec * 1_000_000.0) + round(((float) $frac) * 1_000_000.0);
    }

    /**
     * @return array{utc: string, t1: float, t2: float, t0?: float}
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
            $body['t0'] = (float) $t0;
        }

        return $body;
    }
}
