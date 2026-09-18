<?php

namespace App\Support;

use App\Models\Device;

class SignageDeviceClock
{
    public static function summary(Device $device): array
    {
        $seenAgo = self::ageSeconds($device->last_seen_at);
        $syncAgo = self::ageSeconds($device->clock_synced_at);

        if ($device->clock_synced_at === null) {
            return [
                'label' => 'no report · install new APK',
                'class' => 'clock-unknown',
                'status' => $device->status,
                'seen' => self::seenLabel($seenAgo),
            ];
        }

        $offset = (int) $device->clock_offset_ms;
        $rtt = (int) $device->clock_rtt_ms;
        $offsetStr = ($offset >= 0 ? '+' : '').$offset.'ms';

        if ($seenAgo === null || $seenAgo > 15) {
            return [
                'label' => "offline · last $offsetStr",
                'class' => 'clock-stale',
                'status' => $device->status,
                'seen' => self::seenLabel($seenAgo),
            ];
        }

        if ($syncAgo !== null && $syncAgo > 45) {
            return [
                'label' => "stale · offset $offsetStr",
                'class' => 'clock-stale',
                'status' => $device->status,
                'seen' => self::seenLabel($seenAgo),
            ];
        }

        $quality = self::quality($rtt);
        $lag = $device->play_lag_ms;
        $lagStr = $lag === null ? '' : ' · lag '.($lag >= 0 ? '+' : '').((int) $lag).'ms';

        return [
            'label' => $quality.' · offset '.$offsetStr.' · rtt '.$rtt.'ms'.$lagStr,
            'class' => match ($quality) {
                'ok' => 'clock-ok',
                'weak' => 'clock-weak',
                'bad' => 'clock-bad',
                default => (static function () use ($quality): never {
                    throw new \UnhandledMatchError($quality);
                })(),
            },
            'status' => $device->status,
            'seen' => self::seenLabel($seenAgo),
        ];
    }

    /**
     * @return 'ok'|'weak'|'bad'
     */
    private static function quality(int $rttMs): string
    {
        if ($rttMs <= 50) {
            return 'ok';
        }
        if ($rttMs <= 120) {
            return 'weak';
        }

        return 'bad';
    }

    private static function ageSeconds(mixed $at): ?int
    {
        if ($at === null) {
            return null;
        }

        return (int) max(0, $at->diffInSeconds(now()));
    }

    private static function seenLabel(?int $seenAgo): string
    {
        if ($seenAgo === null) {
            return 'never';
        }

        return $seenAgo.'s ago';
    }
}
