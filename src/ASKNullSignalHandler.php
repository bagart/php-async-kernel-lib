<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel;

use BAGArt\AsyncKernel\Contracts\ASKSignalHandlerContract;

/**
 * No-op signal handler: only the built-in shutdown/force flags are tracked.
 */
final class ASKNullSignalHandler implements ASKSignalHandlerContract
{
    public function graceful(int $signal): void
    {
        unset($signal);
    }

    public function force(int $signal): void
    {
        unset($signal);
    }
}
