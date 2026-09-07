<?php

namespace App\Support;

use Carbon\Carbon;

class SignageTimeline
{
    public static function origin(int $minDelaySeconds = 8, int $alignSeconds = 5): Carbon
    {
        $min = now()->utc()->addSeconds($minDelaySeconds);
        $step = max(1, $alignSeconds);
        $aligned = (int) (ceil($min->getTimestamp() / $step) * $step);

        return Carbon::createFromTimestampUTC($aligned);
    }
}
