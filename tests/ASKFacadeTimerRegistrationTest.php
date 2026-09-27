<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\ASK;
use BAGArt\AsyncKernel\AsyncKernel;
use BAGArt\AsyncKernel\Promise\Awaitables\ASKSleepAwaitable;
use BAGArt\AsyncKernel\Timer\ASKTimer;
use BAGArt\AsyncKernel\Wrappers\ASKLogWrapper;
use Psr\Log\NullLogger;

describe('ASK facade timer registration', function () {
    $kernelTimer = static function (AsyncKernel $kernel): ASKTimer {
        return (new ReflectionProperty(AsyncKernel::class, 'timer'))->getValue($kernel);
    };

    $newKernel = static fn (): AsyncKernel => new AsyncKernel(
        logger: new ASKLogWrapper(logger: new NullLogger()),
    );

    it('wires ASK::sleep() to the timer of the kernel it constructs', function () use ($kernelTimer, $newKernel) {
        $kernel = $newKernel();

        $sleep = ASK::sleep(5);

        expect($sleep)->toBeInstanceOf(ASKSleepAwaitable::class);
        expect($kernelTimer($kernel)->queueSize())->toBe(1);
    });

    it('last constructed kernel owns the process-wide facade timer', function () use ($kernelTimer, $newKernel) {
        $first = $newKernel();
        $second = $newKernel();

        ASK::sleep(5);

        expect($kernelTimer($first)->queueSize())->toBe(0);
        expect($kernelTimer($second)->queueSize())->toBe(1);
    });

    it('ASK::setTimer() re-points the facade explicitly', function () use ($kernelTimer, $newKernel) {
        $first = $newKernel();
        $second = $newKernel();

        ASK::setTimer($kernelTimer($first));
        ASK::sleep(5);

        expect($kernelTimer($first)->queueSize())->toBe(1);
        expect($kernelTimer($second)->queueSize())->toBe(0);

        ASK::setTimer($kernelTimer($second));
    });
});
