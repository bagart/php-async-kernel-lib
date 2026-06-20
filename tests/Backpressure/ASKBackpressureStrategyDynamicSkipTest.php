<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\Backpressure\ASKBackpressureStrategyDynamicSkip;

describe('ASKBackpressureStrategyDynamicSkip', function () {
    it('always allows when systemPressure < pressureMin', function () {
        $strategy = new ASKBackpressureStrategyDynamicSkip(
            pressureMin: 50,
            pressureMax: 100,
        );

        for ($i = 0; $i < 100; $i++) {
            expect($strategy->backpressure(systemPressure: 49, currentPressure: 0))->toBeTrue();
            expect($strategy->backpressure(systemPressure: 0, currentPressure: 0))->toBeTrue();
            expect($strategy->backpressure(systemPressure: 30, currentPressure: 80))->toBeTrue();
        }
    });

    it('always skips when systemPressure >= pressureMax', function () {
        $strategy = new ASKBackpressureStrategyDynamicSkip(
            pressureMin: 50,
            pressureMax: 100,
        );

        for ($i = 0; $i < 100; $i++) {
            expect($strategy->backpressure(systemPressure: 100, currentPressure: 0))->toBeFalse();
            expect($strategy->backpressure(systemPressure: 150, currentPressure: 0))->toBeFalse();
            expect($strategy->backpressure(systemPressure: 1000, currentPressure: 50))->toBeFalse();
        }
    });

    it('always allows when currentPressure >= currentPressureMax', function () {
        $strategy = new ASKBackpressureStrategyDynamicSkip(
            pressureMin: 50,
            pressureMax: 100,
            currentPressureMax: 80,
        );

        for ($i = 0; $i < 100; $i++) {
            expect($strategy->backpressure(systemPressure: 200, currentPressure: 80))->toBeTrue();
            expect($strategy->backpressure(systemPressure: 200, currentPressure: 100))->toBeTrue();
            expect($strategy->backpressure(systemPressure: 200, currentPressure: 500))->toBeTrue();
        }
    });

    it('does not use currentPressureMax when null', function () {
        $strategy = new ASKBackpressureStrategyDynamicSkip(
            pressureMin: 50,
            pressureMax: 100,
            currentPressureMax: null,
        );

        for ($i = 0; $i < 100; $i++) {
            expect($strategy->backpressure(systemPressure: 200, currentPressure: 200))->toBeFalse();
        }
    });

    it('probabilistic skip between pressureMin and pressureMax', function () {
        $strategy = new ASKBackpressureStrategyDynamicSkip(
            pressureMin: 50,
            pressureMax: 100,
        );

        $allowed = 0;
        $trials = 10_000;
        for ($i = 0; $i < $trials; $i++) {
            if ($strategy->backpressure(systemPressure: 75, currentPressure: 0)) {
                $allowed++;
            }
        }

        $ratio = $allowed / $trials;
        expect($ratio)->toBeGreaterThan(0.35)
            ->toBeLessThan(0.65);
    });

    it('near pressureMin has high allow rate', function () {
        $strategy = new ASKBackpressureStrategyDynamicSkip(
            pressureMin: 50,
            pressureMax: 100,
        );

        $allowed = 0;
        $trials = 10_000;
        for ($i = 0; $i < $trials; $i++) {
            if ($strategy->backpressure(systemPressure: 55, currentPressure: 0)) {
                $allowed++;
            }
        }

        $ratio = $allowed / $trials;
        expect($ratio)->toBeGreaterThan(0.8);
    });

    it('near pressureMax has low allow rate', function () {
        $strategy = new ASKBackpressureStrategyDynamicSkip(
            pressureMin: 50,
            pressureMax: 100,
        );

        $allowed = 0;
        $trials = 10_000;
        for ($i = 0; $i < $trials; $i++) {
            if ($strategy->backpressure(systemPressure: 95, currentPressure: 0)) {
                $allowed++;
            }
        }

        $ratio = $allowed / $trials;
        expect($ratio)->toBeGreaterThan(0.0)
            ->toBeLessThan(0.2);
    });
});
