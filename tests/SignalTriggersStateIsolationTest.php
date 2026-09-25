<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\SignalTriggers;

describe('SignalTriggers state isolation', function () {
    beforeEach(function () {
        SignalTriggers::reset();
    });

    afterEach(function () {
        SignalTriggers::reset();
    });

    it('reset() clears shutdownRequested flag', function () {
        // Manually set state via reflection to simulate prior test pollution
        $ref = new ReflectionProperty(SignalTriggers::class, 'shutdownRequested');
        $ref->setValue(null, true);

        expect(SignalTriggers::isShutdownRequested())->toBeTrue();

        SignalTriggers::reset();

        expect(SignalTriggers::isShutdownRequested())->toBeFalse();
    });

    it('reset() clears forceRequested flag', function () {
        $ref = new ReflectionProperty(SignalTriggers::class, 'forceRequested');
        $ref->setValue(null, true);

        expect(SignalTriggers::isForceRequested())->toBeTrue();

        SignalTriggers::reset();

        expect(SignalTriggers::isForceRequested())->toBeFalse();
    });

    it('reset() is idempotent', function () {
        SignalTriggers::reset();
        SignalTriggers::reset();
        SignalTriggers::reset();

        expect(SignalTriggers::isShutdownRequested())->toBeFalse();
        expect(SignalTriggers::isForceRequested())->toBeFalse();
    });

    it('static state starts clean in new test context', function () {
        // After beforeEach reset, both flags must be false
        expect(SignalTriggers::isShutdownRequested())->toBeFalse();
        expect(SignalTriggers::isForceRequested())->toBeFalse();
    });
});
