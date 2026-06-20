<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel;

use BAGArt\AsyncKernel\Enum\ShutdownPhase;

/**
 * Immutable shutdown context passed to daemons during shutdown.
 *
 * The context is recreated by AsyncKernel on each phase transition —
 * daemons always receive the current phase. There is no public
 * transitionTo() method, preventing daemons from escalating the phase.
 */
final class ASKShutdownContext
{
    public function __construct(
        private readonly ShutdownPhase $phase,
        private readonly bool $forced,
        private readonly float $deadline,
    ) {
    }

    public function phase(): ShutdownPhase
    {
        return $this->phase;
    }

    public function isStopping(): bool
    {
        return $this->phase === ShutdownPhase::STOPPING;
    }

    public function isDraining(): bool
    {
        return $this->phase === ShutdownPhase::DRAINING;
    }

    public function isForcing(): bool
    {
        return $this->forced || $this->phase === ShutdownPhase::FORCING;
    }

    public function remainingTime(): float
    {
        return max(0.0, $this->deadline - microtime(true));
    }
}
