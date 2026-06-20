<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Contracts;

use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
use Closure;
use Fiber;

interface ASKSchedulerContract extends ASKTickableContract
{
    public function enqueue(Fiber|Closure $fiber): void;
}
