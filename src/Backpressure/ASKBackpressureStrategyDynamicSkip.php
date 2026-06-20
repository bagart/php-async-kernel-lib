<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Backpressure;

use BAGArt\AsyncKernel\Contracts\ASKBackpressureStrategyContract;

/**
 * Probabilistic skip strategy for adaptive backpressure.
 *
 * - If currentPressure >= currentPressureMax → always allow (component may be
 *   the source of pressure and must keep working to drain itself).
 * - If systemPressure < pressureMin → always allow.
 * - If systemPressure >= pressureMax → always skip.
 * - Between pressureMin and pressureMax → probabilistic skip: higher pressure
 *   means lower probability of execution.
 */
final class ASKBackpressureStrategyDynamicSkip implements ASKBackpressureStrategyContract
{
    public function __construct(
        private readonly ?int $pressureMin = 50,
        private readonly int $pressureMax = 100,
        private readonly ?int $currentPressureMax = null,
    ) {
    }

    public function backpressure(int $systemPressure, int $currentPressure): bool
    {
        if (
            $this->currentPressureMax !== null
            && $currentPressure >= $this->currentPressureMax
        ) {
            return true;
        }

        if (
            $this->pressureMin !== null
            && $systemPressure < $this->pressureMin
        ) {
            return true;
        }

        if ($systemPressure >= $this->pressureMax) {
            return false;
        }

        $min = $this->pressureMin ?? 0;
        $range = $this->pressureMax - $min;
        if ($range <= 0) {
            return false;
        }

        $ratio = ($systemPressure - $min) / $range;
        $probability = 1.0 - $ratio;

        return (random_int(0, PHP_INT_MAX) / PHP_INT_MAX) < $probability;
    }
}
