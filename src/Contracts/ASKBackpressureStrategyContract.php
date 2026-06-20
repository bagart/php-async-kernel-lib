<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Contracts;

interface ASKBackpressureStrategyContract
{
    /**
     * Decide whether the current tick/produce cycle should execute.
     *
     * @param  int $systemPressure   Maximum pressure across all components (0..N, 100 = design limit)
     * @param  int $currentPressure  Pressure of the component asking (0..N, 100 = design limit)
     */
    public function backpressure(int $systemPressure, int $currentPressure): bool;
}
