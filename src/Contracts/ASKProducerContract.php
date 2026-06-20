<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Contracts;

interface ASKProducerContract
{
    /**
     * Check whether enough time has elapsed since the last production cycle.
     */
    public function canProduce(): bool;

    /**
     * Execute the production cycle under the given system pressure.
     */
    public function produce(int $systemPressure): void;

    /**
     * Relative pressure of this component.
     *
     * 100 = design limit. >100 = overloaded (e.g. 1000 = 10x overload).
     */
    public function pressure(): int;
}
