<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel;

use BAGArt\AsyncKernel\Contracts\ASKClockContract;
use DateInterval;

use function hrtime;
use function microtime;
use function time;
use function usleep;

final class ASKClock implements ASKClockContract
{
    public function microtime(): float
    {
        return microtime(true);
    }

    public function time(): int
    {
        return time();
    }

    /**
     * Monotonic milliseconds since an arbitrary epoch (hrtime-based).
     *
     * Use this for deadline arithmetic (timer expirations, timeouts) —
     * never affected by wall-clock jumps (NTP, DST, manual changes),
     * unlike {@see time()} / {@see microtime()}.
     */
    public function timeMs(): int
    {
        return (int)(hrtime(true) / ASKClockContract::NS_PER_MS);
    }

    public function hrtime(): int
    {
        return hrtime(true);
    }

    public function sleep(int $microseconds): void
    {
        if ($microseconds <= 0) {
            return;
        }

        $start = $this->hrtime();
        $targetNs = $microseconds * ASKClockContract::NS_PER_US;

        while (($this->hrtime() - $start) < $targetNs) {
            $remainingNs = $targetNs - ($this->hrtime() - $start);

            if ($remainingNs > 50_000) {
                usleep((int)($remainingNs / ASKClockContract::NS_PER_US));
            }
        }
    }

    public function getSecondsFromInterval(DateInterval $interval): int
    {
        return ($interval->y * 31536000) // 365d
            + ($interval->m * 2592000)  // 30d
            + ($interval->d * 86400)
            + ($interval->h * 3600)
            + ($interval->i * 60)
            + $interval->s;
    }
}
