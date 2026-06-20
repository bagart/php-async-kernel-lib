<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Contracts;

/**
 * A lease returned by watchRead/watchWrite that tracks socket observation.
 *
 * The lease does NOT close the underlying resource — that is the
 * responsibility of the resource owner (Invariant 6).
 */
interface ASKResourceLease
{
    /**
     * Release the lease: unsubscribe from socket observation.
     * Safe to call multiple times.
     */
    public function release(): void;

    /**
     * Cancel the lease (called by scheduler during forceStop).
     * Same effect as release — unsubscribes from observation.
     * Does NOT close the resource.
     */
    public function cancel(): void;
}
