<?php

namespace App\Support;

use Carbon\Carbon;

class SignageTimeline
{
    public static function origin(int $minDelaySeconds = 8, int $alignSeconds = 1): Carbon
    {
        $min = now()->utc()->addSeconds($minDelaySeconds);
        $step = max(1, $alignSeconds);
        $aligned = (int) (ceil($min->getTimestamp() / $step) * $step);

        return Carbon::createFromTimestampUTC($aligned);
    }

    public static function epochMs(Carbon $time): int
    {
        return (int) round($time->getPreciseTimestamp(3));
    }

    /**
     * @param  list<int>  $durationsMs
     * @return array{next_cut_at: Carbon, remaining_ms: int, cycle_ms: int, index: int, position_ms: int}
     */
    public static function nextBoundary(Carbon $origin, array $durationsMs, ?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy()->utc();
        $origin = $origin->copy()->utc();
        $total = 0;
        foreach ($durationsMs as $ms) {
            $total += max(0, (int) $ms);
        }

        if ($total <= 0) {
            return [
                'next_cut_at' => $origin,
                'remaining_ms' => 0,
                'cycle_ms' => 0,
                'index' => -1,
                'position_ms' => 0,
            ];
        }

        $elapsed = self::epochMs($now) - self::epochMs($origin);
        if ($elapsed < 0) {
            return [
                'next_cut_at' => $origin,
                'remaining_ms' => -$elapsed,
                'cycle_ms' => $total,
                'index' => -1,
                'position_ms' => 0,
            ];
        }

        $mod = $elapsed % $total;
        $cycleStart = $elapsed - $mod;
        $cursor = 0;
        foreach ($durationsMs as $i => $raw) {
            $duration = max(0, (int) $raw);
            $end = $cursor + $duration;
            if ($mod < $end) {
                return [
                    'next_cut_at' => $origin->copy()->addMilliseconds($cycleStart + $end),
                    'remaining_ms' => $end - $mod,
                    'cycle_ms' => $total,
                    'index' => (int) $i,
                    'position_ms' => $mod - $cursor,
                ];
            }
            $cursor = $end;
        }

        return [
            'next_cut_at' => $origin->copy()->addMilliseconds($cycleStart + $total),
            'remaining_ms' => 0,
            'cycle_ms' => $total,
            'index' => 0,
            'position_ms' => 0,
        ];
    }
}
