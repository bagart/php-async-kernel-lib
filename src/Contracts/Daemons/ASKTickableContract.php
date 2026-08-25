<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Contracts\Daemons;

interface ASKTickableContract
{
    /**
     * Performs one iteration under the given system pressure.
     *
     * Pressure semantics (06 §44–§46): $systemPressure is the max of all
     * tickable/producer pressure() values, 100 = design limit. Implementations
     * must degrade gracefully instead of failing: shed or defer non-critical
     * work as pressure rises (e.g. the outbound daemon stops draining low
     * priority lanes above 70 and drains only Critical at >= 95), never drop
     * critical work. Work deferred under pressure stays queued — zero loss.
     */
    public function tick(int $systemPressure): void;

    /**
     * Relative pressure of this component.
     *
     * 100 = design limit. >100 = overloaded (e.g. 1000 = 10x overload).
     */
    public function pressure(): int;

    /**
     * can be faster then queueSize() === 0
     */
    public function isIdle(): bool;

    public function queueSize(): int;
}
