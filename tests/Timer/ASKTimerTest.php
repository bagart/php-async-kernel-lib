<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\Contracts\ASKClockContract;
use BAGArt\AsyncKernel\Promise\Awaitables\ASKSleepAwaitable;
use BAGArt\AsyncKernel\Timer\ASKTimer;

/**
 * Controllable clock for deterministic timer tests.
 */
final class FakeClock implements ASKClockContract
{
    private int $nowMs;

    public function __construct(int $nowMs = 1_000_000)
    {
        $this->nowMs = $nowMs;
    }

    public function advance(int $ms): void
    {
        $this->nowMs += $ms;
    }

    public function set(int $nowMs): void
    {
        $this->nowMs = $nowMs;
    }

    public function microtime(): float
    {
        return $this->nowMs / 1_000;
    }

    public function time(): int
    {
        return (int)($this->nowMs / 1_000);
    }

    public function timeMs(): int
    {
        return $this->nowMs;
    }

    public function hrtime(): int
    {
        return $this->nowMs * ASKClockContract::NS_PER_MS;
    }

    public function sleep(int $microseconds): void
    {
        $this->nowMs += (int)($microseconds / 1_000);
    }

    public function getSecondsFromInterval(DateInterval $interval): int
    {
        return 0;
    }
}

describe('ASKTimer', function () {
    it('is idle with no scheduled timers', function () {
        $timer = new ASKTimer(new FakeClock());

        expect($timer->isIdle())->toBeTrue()
            ->and($timer->queueSize())->toBe(0);
        $timer->tick(0);
    });

    it('does not resolve an awaitable before its deadline', function () {
        $clock = new FakeClock();
        $timer = new ASKTimer($clock);

        $awaitable = $timer->sleep(100);

        expect($awaitable->isCompleted())->toBeFalse();
        $timer->tick(0);
        expect($awaitable->isCompleted())->toBeFalse()
            ->and($timer->queueSize())->toBe(1);
    });

    it('resolves the awaitable once the deadline is reached', function () {
        $clock = new FakeClock();
        $timer = new ASKTimer($clock);

        $awaitable = $timer->sleep(100);

        $clock->advance(99);
        $timer->tick(0);
        expect($awaitable->isCompleted())->toBeFalse();

        $clock->advance(1);
        $timer->tick(0);
        expect($awaitable->isCompleted())->toBeTrue()
            ->and($timer->queueSize())->toBe(0)
            ->and($timer->isIdle())->toBeTrue();
    });

    it('resolves multiple timers independently in one tick', function () {
        $clock = new FakeClock();
        $timer = new ASKTimer($clock);

        $a = $timer->sleep(50);
        $b = $timer->sleep(100);

        $clock->advance(50);
        $timer->tick(0);
        expect($a->isCompleted())->toBeTrue()
            ->and($b->isCompleted())->toBeFalse();

        $clock->advance(50);
        $timer->tick(0);
        expect($b->isCompleted())->toBeTrue();
    });

    it('sync await busy-pumps the timer until resolved (no Fiber)', function () {
        $clock = new FakeClock();
        $timer = new ASKTimer($clock);

        // Use a real clock-based timer so the busy pump actually advances time.
        $realTimer = new ASKTimer();
        $start = hrtime(true);
        $realTimer->sleep(5)->await();
        $elapsedMs = (int)((hrtime(true) - $start) / 1_000_000);

        expect($elapsedMs)->toBeGreaterThanOrEqual(4)
            ->and($elapsedMs)->toBeLessThan(500);
    });

    it('fiber await suspends and is resumed by timer tick', function () {
        $timer = new ASKTimer();

        $fiber = new Fiber(static function () use ($timer): string {
            $timer->sleep(5)->await();

            return 'resumed';
        });

        $fiber->start();

        expect($fiber->isSuspended())->toBeTrue();

        // Spin timer ticks until the fiber terminates.
        $deadline = hrtime(true) + 1_000_000_000;
        while ($fiber->isSuspended() && hrtime(true) < $deadline) {
            $timer->tick(0);
        }

        expect($fiber->isTerminated())->toBeTrue()
            ->and($fiber->getReturn())->toBe('resumed');
    });
});

describe('ASKSleepAwaitable', function () {
    it('exposes its wake deadline', function () {
        $clock = new FakeClock(1_000);
        $timer = new ASKTimer($clock);

        $awaitable = $timer->sleep(200);

        expect($awaitable)->toBeInstanceOf(ASKSleepAwaitable::class)
            ->and($awaitable->wakeAtMs())->toBe(1_200);
    });

    it('await returns immediately if already completed', function () {
        $clock = new FakeClock();
        $timer = new ASKTimer($clock);

        $awaitable = $timer->sleep(10);
        $clock->advance(10);
        $timer->tick(0);

        expect($awaitable->isCompleted())->toBeTrue()
            ->and($awaitable->await())->toBeNull();
    });
});
