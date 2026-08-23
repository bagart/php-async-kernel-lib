<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Tests;

use BAGArt\AsyncKernel\Contracts\ASKSignalHandlerContract;
use BAGArt\AsyncKernel\SignalTriggers;

/**
 * Test fixture: records which contract methods fired for which signals.
 */
final class RecordingSignalHandler implements ASKSignalHandlerContract
{
    /** @var list<int> */
    public array $gracefulSignals = [];

    /** @var list<int> */
    public array $forceSignals = [];

    public function graceful(int $signal): void
    {
        $this->gracefulSignals[] = $signal;
    }

    public function force(int $signal): void
    {
        $this->forceSignals[] = $signal;
    }
}

describe('SignalTriggers', function () {
    beforeEach(function () {
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            $this->markTestSkipped('Requires pcntl and posix extensions.');
        }

        SignalTriggers::reset();
    });

    afterEach(function () {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGUSR2, SIG_DFL);
            pcntl_signal(SIGHUP, SIG_DFL);
        }

        SignalTriggers::reset();
    });

    /**
     * Force synchronous dispatch of pending self-addressed signals.
     */
    $deliver = static function (): void {
        pcntl_signal_dispatch();
        usleep(50_000);
        pcntl_signal_dispatch();
    };

    it('marks shutdown and calls the handler gracefully on the first signal', function () use ($deliver) {
        $handler = new RecordingSignalHandler();
        SignalTriggers::register(signals: [SIGUSR2], handler: $handler);

        expect(posix_kill(getmypid(), SIGUSR2))->toBeTrue();
        $deliver();

        expect(SignalTriggers::isShutdownRequested())->toBeTrue();
        expect(SignalTriggers::isForceRequested())->toBeFalse();
        expect($handler->gracefulSignals)->toBe([SIGUSR2]);
        expect($handler->forceSignals)->toBe([]);
    });

    it('escalates to force on a repeated signal', function () use ($deliver) {
        $handler = new RecordingSignalHandler();
        SignalTriggers::register(signals: [SIGUSR2], handler: $handler);

        posix_kill(getmypid(), SIGUSR2);
        $deliver();
        posix_kill(getmypid(), SIGUSR2);
        $deliver();

        expect(SignalTriggers::isShutdownRequested())->toBeTrue();
        expect(SignalTriggers::isForceRequested())->toBeTrue();
        expect($handler->gracefulSignals)->toBe([SIGUSR2]);
        expect($handler->forceSignals)->toBe([SIGUSR2]);
    });

    it('forces immediately for immediateForceSignals', function () use ($deliver) {
        $handler = new RecordingSignalHandler();
        SignalTriggers::register(signals: [SIGUSR2], handler: $handler, immediateForceSignals: [SIGHUP]);

        posix_kill(getmypid(), SIGHUP);
        $deliver();

        expect(SignalTriggers::isForceRequested())->toBeTrue();
        expect($handler->gracefulSignals)->toBe([]);
        expect($handler->forceSignals)->toBe([SIGHUP]);
    });

    it('tracks flags with no handler via the null-object default', function () use ($deliver) {
        SignalTriggers::register(signals: [SIGUSR2]);

        posix_kill(getmypid(), SIGUSR2);
        $deliver();

        expect(SignalTriggers::isShutdownRequested())->toBeTrue();

        posix_kill(getmypid(), SIGUSR2);
        $deliver();

        expect(SignalTriggers::isForceRequested())->toBeTrue();
    });

    it('reset() clears both flags', function () {
        SignalTriggers::reset();

        expect(SignalTriggers::isShutdownRequested())->toBeFalse();
        expect(SignalTriggers::isForceRequested())->toBeFalse();
    });
});

describe('ASKNullSignalHandler', function () {
    it('accepts signals as a no-op', function () {
        $handler = new \BAGArt\AsyncKernel\ASKNullSignalHandler();

        $handler->graceful(SIGINT);
        $handler->force(SIGTERM);

        expect($handler)->toBeInstanceOf(ASKSignalHandlerContract::class);
    });
});
