<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Drivers;

use BAGArt\AsyncKernel\Contracts\ASKResourceLease;
use BAGArt\AsyncKernel\Contracts\ASKSchedulerContract;
use BAGArt\AsyncKernel\Contracts\ASKSocketSchedulerContract;
use BAGArt\AsyncKernel\Exceptions\ASKForceShutdownException;
use Closure;
use Fiber;
use SplObjectStorage;
use SplQueue;
use Throwable;
use WeakReference;

final class ASKFiberScheduler implements ASKSchedulerContract, ASKSocketSchedulerContract
{
    private const int FAST_POLL_US = 10_000;
    private const int NORMAL_POLL_US = 50_000;
    private const int SLOW_POLL_US = 200_000;
    private const int DEFAULT_BATCH_SIZE = 10;

    /** @var SplQueue<Fiber> */
    private SplQueue $queue;

    /** @var array<int, array<Fiber>> */
    private array $sleeping = [];

    /** @var array<int, resource> */
    private array $readSockets = [];

    /** @var array<int, resource> */
    private array $writeSockets = [];

    /** @var array<int, Fiber> */
    private array $waitingReadFibers = [];

    /** @var array<int, Fiber> */
    private array $waitingWriteFibers = [];

    /** @var array<int, ASKResourceLease> */
    private array $leases = [];

    private bool $stopped = false;

    private ?float $pollingSocketsSince = null;

    public function __construct(
        private readonly int $batchSize = self::DEFAULT_BATCH_SIZE,
    ) {
        $this->queue = new SplQueue();
    }

    public function enqueue(Fiber|Closure $fiber): void
    {
        if ($fiber instanceof Fiber) {
            if ($fiber->isTerminated()) {
                return;
            }
        } else {
            $fiber = new Fiber($fiber);
        }
        $this->queue->enqueue($fiber);
    }

    public function queueSize(): int
    {
        return $this->queue->count();
    }

    public function isIdle(): bool
    {
        return $this->queue->isEmpty()
            && $this->sleeping === []
            && $this->readSockets === []
            && $this->writeSockets === [];
    }

    public function pressure(): int
    {
        $socketCount = count($this->readSockets) + count($this->writeSockets);

        if ($socketCount === 0) {
            return 0;
        }

        return (int)min(100, ($socketCount / 50) * 100);
    }

    public function forceStop(?Closure $onFiberForceStopped = null): void
    {
        $fibers = $this->collectFibers();
        $this->throwIntoFibers($fibers, $onFiberForceStopped);
        $this->drainCancelledFibers($fibers);
        $this->cleanup();
    }

    public function watchRead(mixed $socket): ASKResourceLease
    {
        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            throw new \RuntimeException('watchRead requires a running Fiber');
        }

        $id = (int)$socket;
        $this->readSockets[$id] = $socket;
        $this->waitingReadFibers[$id] = $fiber;

        $lease = new ASKFiberSchedulerSocketLease(
            schedulerRef: WeakReference::create($this),
            socketId: $id,
            isWrite: false,
        );
        $this->leases[$id] = $lease;

        return $lease;
    }

    public function unwatchRead(mixed $socket): void
    {
        $id = (int)$socket;
        unset($this->readSockets[$id], $this->waitingReadFibers[$id], $this->leases[$id]);
    }

    public function unwatchReadByResourceId(int $socketId): void
    {
        unset($this->readSockets[$socketId], $this->waitingReadFibers[$socketId], $this->leases[$socketId]);
    }

    public function watchWrite(mixed $socket): ASKResourceLease
    {
        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            throw new \RuntimeException('watchWrite requires a running Fiber');
        }

        $id = (int)$socket;
        $this->writeSockets[$id] = $socket;
        $this->waitingWriteFibers[$id] = $fiber;

        $lease = new ASKFiberSchedulerSocketLease(
            schedulerRef: WeakReference::create($this),
            socketId: $id,
            isWrite: true,
        );
        $this->leases[$id] = $lease;

        return $lease;
    }

    public function unwatchWrite(mixed $socket): void
    {
        $id = (int)$socket;
        unset($this->writeSockets[$id], $this->waitingWriteFibers[$id], $this->leases[$id]);
    }

    public function unwatchWriteByResourceId(int $socketId): void
    {
        unset($this->writeSockets[$socketId], $this->waitingWriteFibers[$socketId], $this->leases[$socketId]);
    }

    // ===== tick =====

    public function tick(int $systemPressure): void
    {
        $this->wakeSleepingFibers();

        $processed = 0;

        while (!$this->queue->isEmpty() && $processed < $this->batchSize) {
            $fiber = $this->queue->dequeue();

            if ($fiber->isTerminated()) {
                $processed++;
                continue;
            }

            if (!$fiber->isStarted()) {
                $fiber->start();
            } elseif ($fiber->isSuspended()) {
                $fiber->resume();
            }

            if (!$fiber->isTerminated() && $fiber->isSuspended()) {
                $this->queue->enqueue($fiber);
            }

            $processed++;
        }

        if ($this->queue->isEmpty()) {
            $this->pollSocketsWithTimeout();
        }
    }

    // ===== pollSockets =====

    private function pollSockets(int $sec, int $usec): void
    {
        if ($this->readSockets === [] && $this->writeSockets === []) {
            return;
        }

        $read = $this->readSockets;
        $write = $this->writeSockets;
        $except = null;

        $changed = @stream_select($read, $write, $except, $sec, $usec);

        if ($changed === false || $changed <= 0) {
            return;
        }

        foreach ($read as $readySocket) {
            $id = (int)$readySocket;

            if (isset($this->waitingReadFibers[$id])) {
                $this->queue->enqueue($this->waitingReadFibers[$id]);
            }
        }

        foreach ($write as $readySocket) {
            $id = (int)$readySocket;

            if (isset($this->waitingWriteFibers[$id])) {
                $this->queue->enqueue($this->waitingWriteFibers[$id]);
            }
        }
    }

    // ===== pollSocketsWithTimeout =====

    private function pollSocketsWithTimeout(): void
    {
        if ($this->readSockets === [] && $this->writeSockets === []) {
            return;
        }

        if ($this->stopped) {
            $this->pollingSocketsSince ??= microtime(true);
            $elapsedMs = (microtime(true) - $this->pollingSocketsSince) * 1000;

            $timeoutUs = match (true) {
                $elapsedMs < 2_000 => self::FAST_POLL_US,
                $elapsedMs < 10_000 => self::NORMAL_POLL_US,
                default => self::SLOW_POLL_US,
            };

            $this->pollSockets(0, $timeoutUs);

            return;
        }

        $this->pollingSocketsSince = null;

        $sec = 1;
        $usec = 0;

        if ($this->sleeping !== []) {
            $nearestWakeup = min(array_keys($this->sleeping));
            $diff = $nearestWakeup - microtime(true);

            if ($diff > 0) {
                $sec = (int)$diff;
                $usec = (int)(($diff - $sec) * 1_000_000);
            } else {
                $sec = 0;
            }
        }

        $this->pollSockets($sec, $usec);
    }

    // ===== wakeSleepingFibers =====

    private function wakeSleepingFibers(): void
    {
        if ($this->sleeping === []) {
            return;
        }

        $now = microtime(true);

        foreach ($this->sleeping as $wakeupTime => $fibers) {
            if ($wakeupTime <= $now) {
                foreach ($fibers as $fiber) {
                    $this->queue->enqueue($fiber);
                }

                unset($this->sleeping[$wakeupTime]);
            }
        }
    }

    // ===== forceStop helpers =====

    private function collectFibers(): SplObjectStorage
    {
        $fibers = new SplObjectStorage();

        foreach ($this->sleeping as $fiberGroup) {
            foreach ($fiberGroup as $fiber) {
                $fibers[$fiber] = null;
            }
        }
        foreach ($this->waitingReadFibers as $fiber) {
            $fibers[$fiber] = null;
        }
        foreach ($this->waitingWriteFibers as $fiber) {
            $fibers[$fiber] = null;
        }
        foreach ($this->queue as $fiber) {
            if ($fiber instanceof Fiber) {
                $fibers[$fiber] = null;
            }
        }

        return $fibers;
    }

    private function throwIntoFibers(SplObjectStorage $fibers, ?Closure $onFiberForceStopped = null): void
    {
        $exception = new ASKForceShutdownException('Scheduler forced to stop');

        foreach ($fibers as $fiber) {
            if (!$fiber->isSuspended() || $fiber->isTerminated()) {
                continue;
            }

            $onFiberForceStopped && $onFiberForceStopped($fiber, $exception);

            try {
                $fiber->throw($exception);
            } catch (\FiberError) {
            } catch (Throwable $e) {
                $onFiberForceStopped && $onFiberForceStopped($fiber, $e);
            }
        }
    }

    private function drainCancelledFibers(SplObjectStorage $fibers): void
    {
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            $allTerminated = true;

            foreach ($fibers as $fiber) {
                if (!$fiber->isTerminated()) {
                    $allTerminated = false;

                    break;
                }
            }

            if ($allTerminated) {
                break;
            }

            $this->tick(0);
        }

        $this->cleanupOrphanedLeases();
    }

    private function cleanup(): void
    {
        $this->sleeping = [];
        $this->readSockets = [];
        $this->writeSockets = [];
        $this->waitingReadFibers = [];
        $this->waitingWriteFibers = [];
        $this->queue = new SplQueue();
        $this->leases = [];
    }

    private function cleanupOrphanedLeases(): void
    {
        foreach ($this->leases as $lease) {
            $lease->cancel();
        }
        $this->leases = [];
    }
}
