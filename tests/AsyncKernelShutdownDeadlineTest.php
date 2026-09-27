<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\ASKShutdownContext;
use BAGArt\AsyncKernel\AsyncKernel;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKDaemonContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKShutdownAware;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
use BAGArt\AsyncKernel\Enum\ExceptionPolicy;
use BAGArt\AsyncKernel\Enum\ShutdownPhase;
use BAGArt\AsyncKernel\Wrappers\ASKLogWrapper;
use Psr\Log\NullLogger;

/**
 * Never finishes draining: records every context deadline it is shown and
 * every wall-clock moment shutdown() was called, so the test can compare the
 * deadline advertised to daemons with the moment the drain loop actually stops.
 */
final class SharedDeadlineSpyDaemon implements ASKDaemonContract, ASKShutdownAware, ASKTickableContract
{
    /** @var list<float> */
    public array $shutdownCallsAt = [];

    /** @var list<float> */
    public array $drainDeadlines = [];

    public function startup(): void
    {
    }

    public function shutdown(ASKShutdownContext $context): bool
    {
        $this->shutdownCallsAt[] = microtime(true);
        $this->drainDeadlines[] = $context->deadline();

        return false;
    }

    public function name(): string
    {
        return 'shared-deadline-spy';
    }

    public function onError(Throwable $e): void
    {
    }

    public function shutdownPriority(): int
    {
        return 50;
    }

    public function shutdownTimeout(): int
    {
        return 5;
    }

    public function prepareShutdown(): void
    {
    }

    public function tick(int $systemPressure): void
    {
    }

    public function pressure(): int
    {
        return 0;
    }

    public function isIdle(): bool
    {
        return true;
    }

    public function queueSize(): int
    {
        return 0;
    }
}

final class SharedDeadlineStopTickable implements ASKTickableContract
{
    private int $ticks = 0;

    public function __construct(
        private readonly AsyncKernel $kernel,
        private readonly int $stopAfter = 2,
    ) {
    }

    public function tick(int $systemPressure): void
    {
        $this->ticks++;

        if ($this->ticks >= $this->stopAfter) {
            $this->kernel->stop('deadline-test-done');
        }
    }

    public function pressure(): int
    {
        return 0;
    }

    public function isIdle(): bool
    {
        return true;
    }

    public function queueSize(): int
    {
        return 0;
    }
}

describe('AsyncKernel shared DRAINING deadline', function () {
    it('advertises the drain budget to daemons and stops the drain at that same deadline', function () {
        $shutdownTimeout = 10;
        $drainTimeoutMs = 400;
        $drainBudget = $drainTimeoutMs / 1000;

        $kernel = new AsyncKernel(
            logger: new ASKLogWrapper(logger: new NullLogger()),
            exceptionPolicy: ExceptionPolicy::IGNORE,
            shutdownTimeout: $shutdownTimeout,
            drainTimeout: $drainTimeoutMs,
        );

        $daemon = new SharedDeadlineSpyDaemon();
        $kernel->addDaemon($daemon);
        $kernel->addTickable(new SharedDeadlineStopTickable(kernel: $kernel, stopAfter: 2));

        $kernel->run();

        expect($daemon->drainDeadlines)->not->toBeEmpty();
        expect($daemon->shutdownCallsAt)->not->toBeEmpty();

        $deadline = $daemon->drainDeadlines[0];
        $firstCall = $daemon->shutdownCallsAt[0];
        $lastCall = $daemon->shutdownCallsAt[count($daemon->shutdownCallsAt) - 1];

        // One deadline is advertised for the whole DRAINING phase.
        expect(array_unique($daemon->drainDeadlines))->toHaveCount(1);

        // The context carries the drain budget, not shutdownTimeout (10s here).
        expect($deadline - $firstCall)->toBeGreaterThan(0.05);
        expect($deadline - $firstCall)->toBeLessThan($drainBudget + 0.5);

        // The drain loop stops at that same shared deadline.
        expect($lastCall)->toBeGreaterThanOrEqual($deadline - 1.0);
        expect($lastCall)->toBeLessThanOrEqual($deadline + 1.0);
        expect($lastCall - $firstCall)->toBeGreaterThan($drainBudget * 0.5);

        expect($kernel->shutdownPhase())->toBe(ShutdownPhase::STOPPED);
    });

    it('drains until the advertised deadline instead of ending far before it', function () {
        $drainTimeoutMs = 300;

        $kernel = new AsyncKernel(
            logger: new ASKLogWrapper(logger: new NullLogger()),
            exceptionPolicy: ExceptionPolicy::IGNORE,
            shutdownTimeout: 30,
            drainTimeout: $drainTimeoutMs,
        );

        $daemon = new SharedDeadlineSpyDaemon();
        $kernel->addDaemon($daemon);
        $kernel->addTickable(new SharedDeadlineStopTickable(kernel: $kernel, stopAfter: 2));

        $kernel->run();

        $deadline = $daemon->drainDeadlines[0];
        $finishedAt = microtime(true);

        // shutdownTimeout is 30s while the drain budget is 300ms: the drain
        // must consume the shared deadline, not a different one — so it must
        // neither end long before the advertised deadline nor outlive it.
        expect($finishedAt - $deadline)->toBeGreaterThan(-1.0);
        expect($finishedAt - $deadline)->toBeLessThan(2.0);
        expect($kernel->shutdownPhase())->toBe(ShutdownPhase::STOPPED);
    });
});
