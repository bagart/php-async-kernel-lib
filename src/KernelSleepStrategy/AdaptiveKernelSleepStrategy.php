<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\KernelSleepStrategy;

use BAGArt\AsyncKernel\Contracts\ASKKernelSleepStrategy;

final class AdaptiveKernelSleepStrategy implements ASKKernelSleepStrategy
{
    private int $idleTicks = 0;

    public function __construct(
        private readonly int $idleMicroseconds = 1_000,
        private readonly int $busyMicroseconds = 0,
    ) {
    }

    public function sleep(bool $madeProgress): void
    {
        if ($madeProgress) {
            $this->idleTicks = 0;

            if ($this->busyMicroseconds > 0) {
                usleep($this->busyMicroseconds);
            }

            return;
        }

        ++$this->idleTicks;

        usleep(
            (int)min(
                $this->idleMicroseconds * (1 + $this->idleTicks / 20),
                1_000,
            )
        );
    }
}
