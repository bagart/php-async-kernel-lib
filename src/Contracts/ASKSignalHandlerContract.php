<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Contracts;

/**
 * Reaction to process signals, registered via {@see \BAGArt\AsyncKernel\SignalTriggers}.
 * Implementations are named classes so lifecycle reactions appear in traces
 * and the type system instead of opaque lambdas.
 */
interface ASKSignalHandlerContract
{
    /**
     * First graceful-stop signal (SIGINT/SIGTERM).
     */
    public function graceful(int $signal): void;

    /**
     * Repeat signal or an immediate-force signal (SIGUSR1/SIGQUIT).
     */
    public function force(int $signal): void;
}
