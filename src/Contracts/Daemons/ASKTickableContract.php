<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Contracts\Daemons;

interface ASKTickableContract
{
    /**
     * Performs one iteration under the given system pressure.
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
