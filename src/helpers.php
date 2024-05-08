<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Number;
use Laravel\Prompts\Progress;

if (!function_exists('progressbar_hint')) {
    function progressbar_hint(float $start, Progress $progress, Limit $limit): void
    {
        RateLimiter::attempt(
            key: $limit->key.$start.$progress->total.$progress->label,
            maxAttempts: $limit->maxAttempts,
            callback: function () use ($start, $progress) {
                $hint = [
                    Number::percentage($progress->percentage() * 100),
                ];

                $duration = microtime(true) - $start;

                if ($duration > 0) {
                    $speed = $progress->progress / $duration;
                    $hint[] = Number::format(round($speed))."/sec";

                    if ($speed > 0) {
                        $time_left = ($progress->total - $progress->progress) / $speed;
                        $elapsed = now()->diffAsCarbonInterval(now()->addSeconds($time_left));
                        $hint[] = $elapsed->forHumans();
                    }
                }

                $progress->hint(implode(' | ', $hint));
            },
            decaySeconds: $limit->decaySeconds
        );
    }
}