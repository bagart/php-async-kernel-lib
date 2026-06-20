<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Contracts\Daemons;

use BAGArt\AsyncKernel\ASKShutdownContext;

interface ASKDaemonContract
{
    public function onError(\Throwable $e): void;

    public function startup(): void;

    /**
     * Graceful shutdown — flush pending work, send final ACKs, etc.
     */
    public function shutdown(ASKShutdownContext $context): bool;

    public function name(): string;
}
