<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Timer;

use BAGArt\AsyncKernel\ASKClock;
use BAGArt\AsyncKernel\Contracts\ASKClockContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
use BAGArt\AsyncKernel\Promise\Awaitables\ASKSleepAwaitable;

final class ASKTimer implements ASKTickableContract
{
    /** @var array<int, ASKSleepAwaitable> keyed by sequence id */
    private array $timers = [];

    private int $seq = 0;

    private bool $stopped = false;

    public function __construct(
        private readonly ASKClockContract $clock = new ASKClock(),
    ) {
    }

    public function sleep(int $milliseconds): ASKSleepAwaitable
    {
        $awaitable = new ASKSleepAwaitable($this, $this->clock->timeMs() + max(0, $milliseconds));
        $this->timers[$this->seq++] = $awaitable;

        return $awaitable;
    }

    /**
     * Called by AsyncKernel during shutdown to wake all sleeping Fibers.
     * On next tick(), all pending timers resolve immediately,
     * resuming suspended Fibers that must check isStopped().
     */
    public function requestStop(): void
    {
        $this->stopped = true;
    }

    public function tick(int $systemPressure): void
    {
        if ($this->timers === []) {
            return;
        }

        $now = $this->clock->timeMs();

        foreach ($this->timers as $id => $awaitable) {
            if ($this->stopped || $now >= $awaitable->wakeAtMs()) {
                $awaitable->resolveByTimer();
                unset($this->timers[$id]);
            }
        }
    }

    public function pressure(): int
    {
        return 0;
    }

    public function isIdle(): bool
    {
        return $this->timers === [];
    }

    public function queueSize(): int
    {
        return count($this->timers);
    }
}
