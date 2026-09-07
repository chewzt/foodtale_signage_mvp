<?php

namespace App\Support;

class MediaDuration
{
    public static function probeMilliseconds(string $absolutePath): ?int
    {
        if (! is_file($absolutePath)) {
            return null;
        }

        $ffprobe = trim((string) shell_exec('command -v ffprobe 2>/dev/null'));
        if ($ffprobe === '') {
            return null;
        }

        $raw = trim((string) shell_exec(
            $ffprobe.' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 '.escapeshellarg($absolutePath)
        ));
        if (! is_numeric($raw)) {
            return null;
        }

        $ms = (int) round(((float) $raw) * 1000);

        return $ms > 0 ? $ms : null;
    }
}
