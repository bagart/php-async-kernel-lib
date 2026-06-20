<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Daemons;

trait ASKShutdownAwareTrait
{
    public function shutdownPriority(): int
    {
        return 50;
    }

    public function shutdownTimeout(): int
    {
        return 10;
    }

    public function prepareShutdown(): void
    {
    }
}
