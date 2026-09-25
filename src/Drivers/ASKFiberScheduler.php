<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Drivers;

use BAGArt\AsyncKernel\Contracts\ASKResourceLease;
use BAGArt\AsyncKernel\Contracts\ASKSchedulerContract;
use BAGArt\AsyncKernel\Contracts\ASKSocketSchedulerContract;
use BAGArt\AsyncKernel\Exceptions\ASKForceShutdownException;
use BAGArt\AsyncKernel\Promise\ASKDeferred;
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

    /** @var SplQueue<Fiber> Chosen for O(1) enqueue/dequeue; under sustained throughput, linked-list nodes may cause allocator fragmentation. A ring buffer would reduce fragmentation but loses O(1) dequeue. */
    private SplQueue $queue;

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

    /** @var SplObjectStorage<Fiber, \BAGArt\AsyncKernel\Promise\ASKDeferred> */
    private SplObjectStorage $fiberFutures;

    private bool $stopped = false;

    private ?float $pollingSocketsSince = null;

    public function __construct(
        private readonly int $batchSize = self::DEFAULT_BATCH_SIZE,
    ) {
        $this->queue = new SplQueue();
        $this->fiberFutures = new SplObjectStorage();
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

    public function track(Fiber $fiber, ASKDeferred $future): void
    {
        $this->fiberFutures[$fiber] = $future;
    }

    public function untrack(Fiber $fiber, ASKDeferred $future): void
    {
        if (isset($this->fiberFutures[$fiber]) && $this->fiberFutures[$fiber] === $future) {
            unset($this->fiberFutures[$fiber]);
        }
    }

    public function isIdle(): bool
    {
        return $this->queue->isEmpty()
            && $this->readSockets === []
            && $this->writeSockets === [];
    }

    public function pressure(): int
    {
        $socketCount = count($this->readSockets) + count($this->writeSockets);
        $queueCount = $this->queue->count();

        $socketPressure = $socketCount === 0 ? 0 : (int) min(100, ($socketCount / 50) * 100);
        $queuePressure = $queueCount === 0 ? 0 : (int) min(100, ($queueCount / 100) * 100);

        return max($socketPressure, $queuePressure);
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
        $processed = 0;

        while (!$this->queue->isEmpty() && $processed < $this->batchSize) {
            $processed++;
            $fiber = $this->queue->dequeue();

            if ($fiber->isTerminated()) {
                $this->untrackByFiber($fiber);
                continue;
            }

            try {
                if (!$fiber->isStarted()) {
                    $fiber->start();
                } elseif ($fiber->isSuspended()) {
                    $fiber->resume();
                }
            } catch (Throwable $e) {
                $this->rejectAndUntrackFiber($fiber, $e);
                continue;
            }

            if ($fiber->isTerminated()) {
                $this->untrackByFiber($fiber);
            } elseif ($fiber->isSuspended()) {
                $this->queue->enqueue($fiber);
            }
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
            $this->pollingSocketsSince ??= hrtime(true) / 1e9;
            $elapsedMs = ((hrtime(true) / 1e9) - $this->pollingSocketsSince) * 1000;

            $timeoutUs = match (true) {
                $elapsedMs < 2_000 => self::FAST_POLL_US,
                $elapsedMs < 10_000 => self::NORMAL_POLL_US,
                default => self::SLOW_POLL_US,
            };

            $this->pollSockets(0, $timeoutUs);

            return;
        }

        $this->pollingSocketsSince = null;

        $this->pollSockets(1, 0);
    }

    // ===== forceStop helpers =====

    private function collectFibers(): SplObjectStorage
    {
        $fibers = new SplObjectStorage();

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
        $deadline = (hrtime(true) / 1e9) + 5;

        while ((hrtime(true) / 1e9) < $deadline) {
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
        $this->readSockets = [];
        $this->writeSockets = [];
        $this->waitingReadFibers = [];
        $this->waitingWriteFibers = [];
        $this->queue = new SplQueue();
        $this->leases = [];
        $this->fiberFutures = new SplObjectStorage();
    }

    private function cleanupOrphanedLeases(): void
    {
        foreach ($this->leases as $lease) {
            $lease->cancel();
        }
        $this->leases = [];
    }

    private function rejectAndUntrackFiber(Fiber $fiber, Throwable $e): void
    {
        if (isset($this->fiberFutures[$fiber])) {
            $future = $this->fiberFutures[$fiber];
            unset($this->fiberFutures[$fiber]);
            $future->reject($e);
        }
    }

    private function untrackByFiber(Fiber $fiber): void
    {
        unset($this->fiberFutures[$fiber]);
    }
}
