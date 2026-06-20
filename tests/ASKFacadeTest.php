<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\ASK;
use BAGArt\AsyncKernel\AsyncKernel;
use BAGArt\AsyncKernel\Exceptions\ASKTechnicalException;
use BAGArt\AsyncKernel\Wrappers\ASKLogWrapper;

describe('ASK facade', function () {
    it('throws if no timer is wired', function () {
        // Reset to unset state for the assertion.
        $ref = new ReflectionClass(ASK::class);
        $prop = $ref->getProperty('timer');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        expect(fn () => ASK::sleep(1))
            ->toThrow(ASKTechnicalException::class);
    });

    it('registers a default timer when AsyncKernel is constructed', function () {
        $kernel = new AsyncKernel(new ASKLogWrapper());

        $sleep = ASK::sleep(5);

        expect($sleep)->toBeInstanceOf(\BAGArt\AsyncKernel\Promise\Awaitables\ASKSleepAwaitable::class);
    });

    it('kernel timer is registered as a tickable', function () {
        $kernel = new AsyncKernel(new ASKLogWrapper());

        expect(ASK::sleep(5))->toBeInstanceOf(\BAGArt\AsyncKernel\Promise\Awaitables\ASKSleepAwaitable::class);
    });
});
