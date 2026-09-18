<?php

namespace App\Support;

use App\Models\Device;
use App\Models\Playlist;
use Illuminate\Support\Collection;

class SignagePlaybackGuard
{
    public const FROZEN_LAG_MS = 4000;

    public const COOLDOWN_SECONDS = 12;

    /**
     * @param  Collection<int, \App\Models\PlaylistItem>  $items
     * @param  array<string, mixed>  $report
     * @return array{action:string, index:int, position_ms:int, lag_ms:int, remaining_ms:int, cycle_ms:int}
     */
    public static function decide(Device $device, ?Playlist $playlist, Collection $items, array $report): array
    {
        $none = [
            'action' => 'none',
            'index' => -1,
            'position_ms' => 0,
            'lag_ms' => 0,
            'remaining_ms' => 0,
            'cycle_ms' => 0,
        ];

        if (! $playlist || $items->isEmpty()) {
            return $none;
        }

        $origin = ($playlist->start_at ?? now())->utc();
        $cut = SignageTimeline::nextBoundary(
            $origin,
            $items->map(fn ($item) => (int) $item->duration_ms)->all(),
        );

        $index = (int) $cut['index'];
        $position = (int) $cut['position_ms'];
        $remaining = (int) $cut['remaining_ms'];
        $base = [
            'action' => 'none',
            'index' => $index,
            'position_ms' => $position,
            'lag_ms' => 0,
            'remaining_ms' => $remaining,
            'cycle_ms' => (int) $cut['cycle_ms'],
        ];

        $waiting = max(0, (int) ($report['waiting_ms'] ?? 0));
        $ready = (bool) ($report['ready'] ?? false);
        if ($index < 0 || $waiting > 0 || ! $ready) {
            return $base;
        }

        $showing = (int) ($report['index'] ?? -1);
        $decoder = max(0, (int) ($report['decoder_ms'] ?? 0));
        $fileMs = max(0, (int) ($report['file_ms'] ?? 0));
        $looping = (bool) ($report['looping'] ?? false);
        $itemId = (int) ($report['item_id'] ?? 0);
        $current = $items->get($index);
        $expectedItemId = (int) ($current?->id ?? 0);

        $wrongClip = $showing !== $index || ($itemId > 0 && $expectedItemId > 0 && $itemId !== $expectedItemId);

        if ($fileMs <= 0) {
            $base['lag_ms'] = 0;
            if ($wrongClip) {
                $base['action'] = 'join';
            }

            return $base;
        }

        $expectedFile = $position;
        if ($looping && $fileMs > 0) {
            $expectedFile = $position % $fileMs;
        } elseif ($fileMs > 0 && $expectedFile > $fileMs) {
            $expectedFile = $fileMs;
        }

        $lag = $expectedFile - $decoder;
        $base['lag_ms'] = $lag;

        $frozen = $lag >= self::FROZEN_LAG_MS;

        if ($wrongClip || $frozen) {
            $base['action'] = 'join';
        }

        return $base;
    }
}
