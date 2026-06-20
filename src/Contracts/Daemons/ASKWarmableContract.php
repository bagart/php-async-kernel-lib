<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Contracts\Daemons;

/**
 * Pre-initializes external connections, pools, or other resources
 * before the kernel tick loop starts.
 *
 * AsyncKernel calls warm() automatically when a daemon or tickable
 * implements this contract during addDaemon()/addTickable().
 */
interface ASKWarmableContract
{
    public function warm(): void;
}
