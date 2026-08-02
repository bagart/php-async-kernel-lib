<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Contracts;

use DateInterval;

interface ASKClockContract
{
    public const int NS_PER_SEC = 1_000_000_000;
    public const int NS_PER_US = 1_000;
    public const int NS_PER_MS = 1_000_000;

    public function microtime(): float;

    public function time(): int;

    public function timeMs(): int;

    public function hrtime(): int;

    public function sleep(int $microseconds): void;

    public function getSecondsFromInterval(DateInterval $interval): int;
}
