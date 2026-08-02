<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Contracts\Daemons;

interface ASKShutdownAware
{
    /**
     * Shutdown priority. Higher = shuts down earlier.
     * MetricsDaemon:    0  (last)
     * QueueDaemon:     50
     * OutboundDaemon: 100  (first)
     */
    public function shutdownPriority(): int;

    /**
     * Max shutdown time in seconds.
     * OutboundDaemon: 30
     * MetricsDaemon:    5
     */
    public function shutdownTimeout(): int;

    /**
     * Called in STOPPING phase.
     * Daemon must stop accepting new tasks (consumer, tg_daemons).
     */
    public function prepareShutdown(): void;
}
