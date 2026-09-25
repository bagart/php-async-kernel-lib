<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Promise\Awaitables;

use BAGArt\AsyncKernel\ASKAwaitable;
use BAGArt\AsyncKernel\Timer\ASKTimer;
use Fiber;

/**
 * Awaitable resolved by an {@see ASKTimer} when its deadline is reached.
 *
 * Two consumption modes:
 * - Inside a Fiber: {@see await()} suspends and the kernel-driven timer
 *   resumes the fiber on resolution (cooperative, non-blocking).
 * - Outside a Fiber: {@see await()} busy-pumps the owning timer's tick()
 *   until resolved. This is a synchronous fallback for callers that are
 *   not driven by an event loop (e.g. ASKClient middleware in sync mode).
 */
final class ASKSleepAwaitable extends ASKAwaitable
{
    public function __construct(
        private readonly ASKTimer $timer,
        private readonly int $wakeAtMs,
    ) {
    }

    public function wakeAtMs(): int
    {
        return $this->wakeAtMs;
    }

    /**
     * Resolve the awaitable. Called by {@see ASKTimer::tick()} when the
     * deadline is reached — not part of the public API.
     *
     * @internal
     */
    public function resolveByTimer(mixed $value = null): void
    {
        $this->resolve($value);
    }

    public function await(): mixed
    {
        if ($this->isCompleted()) {
            return $this->result();
        }

        $fiber = Fiber::getCurrent();

        if ($fiber !== null) {
            $this->onCompleted(
                static function () use ($fiber): void {
                    if ($fiber->isSuspended()) {
                        $fiber->resume();
                    }
                }
            );

            Fiber::suspend();

            return $this->result();
        }

        // Sync fallback: no Fiber / no event loop is driving us.
        // Pump the timer inline until this awaitable resolves.
        // Guard against infinite loop: max 30 seconds of busy-pumping.
        $deadline = (hrtime(true) / 1e9) + 30.0;
        $maxIterations = 100_000;
        $iterations = 0;

        while (!$this->isCompleted()) {
            if (++$iterations >= $maxIterations || (hrtime(true) / 1e9) >= $deadline) {
                throw new \RuntimeException(
                    '[ASKSleepAwaitable] Sync fallback timed out after 30s — '
                    . 'await() must be called inside a Fiber or with a running event loop'
                );
            }
            $this->timer->tick(0);
        }

        return $this->result();
    }
}
