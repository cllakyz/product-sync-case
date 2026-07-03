<?php

namespace App\Sync\Support;

/**
 * Thin, swappable wrapper around sleep so throttling/backoff delays can be
 * asserted (and no-op'd) in tests instead of actually blocking the suite.
 */
class Sleeper
{
    public function seconds(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        usleep((int) round($seconds * 1_000_000));
    }
}
