<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel;

use BAGArt\AsyncKernel\Contracts\ASKClockContract;
use BAGArt\AsyncKernel\Contracts\ASKKernelSleepStrategy;
use BAGArt\AsyncKernel\Contracts\ASKProducerContract;
use BAGArt\AsyncKernel\Contracts\AsyncKernelContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKDaemonContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKShutdownAware;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKWarmableContract;
use BAGArt\AsyncKernel\Contracts\Daemons\WithASKProducerContract;
use BAGArt\AsyncKernel\Contracts\Daemons\WithASKTickableContract;
use BAGArt\AsyncKernel\Drivers\ASKFiberScheduler;
use BAGArt\AsyncKernel\Enum\ExceptionPolicy;
use BAGArt\AsyncKernel\Enum\ShutdownPhase;
use BAGArt\AsyncKernel\Exceptions\ASKInterruptException;
use BAGArt\AsyncKernel\Exceptions\ASKTechnicalException;
use BAGArt\AsyncKernel\KernelSleepStrategy\AdaptiveKernelSleepStrategy;
use BAGArt\AsyncKernel\Timer\ASKTimer;
use BAGArt\AsyncKernel\Wrappers\ASKLogWrapper;
use Fiber;
use Throwable;

final class AsyncKernel implements AsyncKernelContract
{
    /** @var ASKDaemonContract[] */
    private array $daemons = [];

    /** @var ASKTickableContract[] */
    private array $tickables = [];

    /** @var ASKProducerContract[] */
    private array $producers = [];

    /** @var array<int, int> */
    private array $producerIntervals = [];

    /** @var array<int, int> */
    private array $lastProduceAt = [];

    /** @var array<int, Fiber> */
    private array $producerFibers = [];

    private bool $isRunning = false;

    private float $lastBackpressureLogAt = 0;

    private readonly ASKTimer $timer;

    private ShutdownPhase $shutdownPhase = ShutdownPhase::RUNNING;

    private ?ASKShutdownContext $shutdownContext = null;

    public function __construct(
        private readonly ASKLogWrapper $logger,
        private readonly ASKKernelSleepStrategy $sleepStrategy = new AdaptiveKernelSleepStrategy(),
        private readonly ExceptionPolicy $exceptionPolicy = ExceptionPolicy::INTERRUPT,
        private int $shutdownTimeout = 5,
        private readonly ASKClockContract $clock = new ASKClock(),
    ) {
        $this->timer = new ASKTimer($this->clock);
        $this->addTickable($this->timer);

        ASK::setTimer($this->timer);
    }

    public function addDaemon(
        ASKDaemonContract $daemon,
        int $producerInterval = 0,
    ): self {
        if (
            !$daemon instanceof ASKTickableContract
            && !$daemon instanceof ASKProducerContract
            && !$daemon instanceof WithASKTickableContract
            && !$daemon instanceof WithASKProducerContract
        ) {
            throw new ASKTechnicalException(
                'Daemon must implement at least one execution contract'
            );
        }

        $this->daemons[spl_object_id($daemon)] = $daemon;

        if ($daemon instanceof ASKWarmableContract) {
            $daemon->warm();
        }

        if ($daemon instanceof ASKTickableContract) {
            $this->addTickable($daemon);
        }

        if ($daemon instanceof ASKProducerContract) {
            $this->addProducer($daemon, $producerInterval);
        }

        if ($daemon instanceof WithASKTickableContract) {
            foreach ($daemon->tickable() as $tickable) {
                $this->addTickable($tickable);
            }
        }

        if ($daemon instanceof WithASKProducerContract) {
            foreach ($daemon->producers() as $producer) {
                $this->addProducer($producer, $producerInterval);
            }
        }

        return $this;
    }

    public function addTickable(
        ASKTickableContract|WithASKTickableContract|null $tickable
    ): self {
        if ($tickable instanceof WithASKTickableContract) {
            foreach ($tickable->tickable() as $sub) {
                if ($sub !== $tickable) {
                    $this->addTickable($sub);
                }
            }
        }

        if ($tickable instanceof ASKTickableContract) {
            $this->tickables[spl_object_id($tickable)] = $tickable;
        }

        if ($tickable instanceof ASKWarmableContract) {
            $tickable->warm();
        }

        return $this;
    }

    public function addProducer(
        ?ASKProducerContract $producer,
        int $producerInterval = 0,
    ): self {
        if ($producer) {
            $id = spl_object_id($producer);
            $this->producers[$id] = $producer;
            $this->producerIntervals[$id] = $producerInterval;
            $this->lastProduceAt[$id] = 0;
        }

        return $this;
    }

    public function run(): void
    {
        try {
            $this->logger->info(
                '[AsyncKernel::run] Started. Press Ctrl+C to stop.'
            );
            $this->startup();

            $this->isRunning = true;
            $this->shutdownPhase = ShutdownPhase::RUNNING;

            while ($this->isRunning) {
                try {
                    $madeProgress = $this->tick();
                } catch (ASKInterruptException $e) {
                    throw $e;
                } catch (Throwable $e) {
                    $this->logger->error(
                        '[AsyncKernel::tick] '.$e::class.": {$e->getMessage()}"
                    );

                    match ($this->exceptionPolicy) {
                        ExceptionPolicy::IGNORE,
                        ExceptionPolicy::RESTART_DAEMON => null,
                        ExceptionPolicy::STOP_KERNEL => $this->stop(
                            "Exception: {$e->getMessage()}"
                        ),
                        ExceptionPolicy::INTERRUPT => throw $e,
                    };

                    $madeProgress = false;
                }
                $this->sleepStrategy->sleep($madeProgress);
            }
        } catch (ASKInterruptException $e) {
            $this->logger->info(
                "[AsyncKernel] Interrupt: {$e->source} - {$e->getMessage()}",
            );

            $this->stop("Interrupt: {$e->source}");
        } catch (Throwable $e) {
            throw $e;
        } finally {
            $this->doShutdown();
        }
    }

    public function startup(): void
    {
        foreach ($this->daemons as $daemon) {
            $daemon->startup();
        }
    }

    public function stop(string $reason = 'break'): void
    {
        $this->logger->info(
            "[AsyncKernel] stop requested: {$reason}"
        );

        $this->isRunning = false;
    }

    public function tick(): bool
    {
        if (SignalTriggers::isForceRequested()) {
            throw new ASKInterruptException('Force shutdown by signal');
        }
        if (SignalTriggers::isShutdownRequested() && $this->isRunning) {
            $this->stop('SIGTERM/SIGINT');
        }

        $madeProgress = false;

        $systemPressure = $this->computeSystemPressure();

        $beforeSizes = $this->snapshotQueueSizes();

        foreach ($this->tickables as $tickable) {
            if (!$this->isRunning) {
                return $madeProgress;
            }
            try {
                $tickable->tick($systemPressure);
            } catch (ASKInterruptException $e) {
                throw $e;
            } catch (Throwable $e) {
                if ($tickable instanceof ASKDaemonContract) {
                    $tickable->onError($e);
                } else {
                    throw $e;
                }
            }
        }

        foreach ($this->producerFibers as $id => $fiber) {
            if ($fiber->isSuspended()) {
                $madeProgress = true;
                continue;
            }

            if (!$fiber->isTerminated()) {
                continue;
            }

            try {
                $fiber->getReturn();
            } catch (ASKInterruptException $e) {
                throw $e;
            } catch (Throwable $e) {
                if (isset($this->producers[$id])) {
                    $this->producers[$id]->onError($e);
                }
            } finally {
                unset($this->producerFibers[$id]);
            }

            $this->lastProduceAt[$id] = $this->clock->time();
            $madeProgress = true;
        }

        foreach ($this->producers as $id => $producer) {
            if (!$this->isRunning) {
                return $madeProgress;
            }

            if (isset($this->producerFibers[$id])) {
                continue;
            }

            try {
                if (
                    $producer->canProduce()
                    && $this->canRun($id)
                    && $this->isRunning
                ) {
                    $fiber = new Fiber(function () use ($producer, $systemPressure): void {
                        $producer->produce($systemPressure);
                    });

                    $fiber->start();

                    if ($fiber->isTerminated()) {
                        $this->lastProduceAt[$id] = $this->clock->time();
                        $madeProgress = true;
                    } else {
                        $this->producerFibers[$id] = $fiber;
                    }
                }
            } catch (ASKInterruptException $e) {
                throw $e;
            } catch (Throwable $e) {
                $producer->onError($e);
            }
        }

        $madeProgress = $madeProgress || ($beforeSizes !== $this->snapshotQueueSizes());

        $this->logBackpressure($systemPressure);

        return $madeProgress;
    }

    private function computeSystemPressure(): int
    {
        $max = 0;
        foreach ($this->tickables as $tickable) {
            $p = $tickable->pressure();
            if ($p > $max) {
                $max = $p;
            }
        }
        foreach ($this->producers as $producer) {
            $p = $producer->pressure();
            if ($p > $max) {
                $max = $p;
            }
        }

        return $max;
    }

    /** @return array<int, int> */
    private function snapshotQueueSizes(): array
    {
        $sizes = [];
        foreach ($this->tickables as $id => $tickable) {
            $size = $tickable->queueSize();
            if ($size) {
                $sizes[$id] = $size;
            }
        }

        return $sizes;
    }

    private function logBackpressure(int $systemPressure): void
    {
        if ($systemPressure <= 100) {
            return;
        }

        $now = $this->clock->time();
        if ($now - $this->lastBackpressureLogAt < 10) {
            return;
        }
        $this->lastBackpressureLogAt = $now;

        $totalQueued = 0;
        foreach ($this->tickables as $tickable) {
            $totalQueued += $tickable->queueSize();
        }

        $this->logger->warning(
            "Outbound queue backpressure: queue={$totalQueued} pressure={$systemPressure}%"
        );
    }

    private function canRun(int $id): bool
    {
        $interval = $this->producerIntervals[$id] ?? 0;

        if ($interval <= 0) {
            return true;
        }

        $lastRun = $this->lastProduceAt[$id] ?? 0;

        if ($lastRun <= 0) {
            return true;
        }

        return $this->clock->time() >= $lastRun + $interval;
    }

    public function isIdle(): bool
    {
        foreach ($this->tickables as $tickable) {
            if (!$tickable->isIdle()) {
                return false;
            }
        }

        return true;
    }

    public function clock(): ASKClockContract
    {
        return $this->clock;
    }

    public function shutdownPhase(): ShutdownPhase
    {
        return $this->shutdownPhase;
    }

    // ===== Shutdown =====

    private function doShutdown(): void
    {
        $notFinished = [];

        try {
            $this->logger->debug('[AsyncKernel] shutdown');

            // Phase STOPPING
            $this->shutdownPhase = ShutdownPhase::STOPPING;
            $this->shutdownContext = new ASKShutdownContext(
                phase: ShutdownPhase::STOPPING,
                forced: SignalTriggers::isForceRequested(),
                deadline: microtime(true) + $this->shutdownTimeout,
            );

            $this->timer->requestStop();
            $this->prepareAllDaemonsShutdown();

            // Phase DRAINING
            $this->shutdownPhase = ShutdownPhase::DRAINING;
            $this->shutdownContext = new ASKShutdownContext(
                phase: ShutdownPhase::DRAINING,
                forced: SignalTriggers::isForceRequested(),
                deadline: microtime(true) + $this->shutdownTimeout,
            );

            $notFinished = $this->drainDaemonsByPriority();

            if ($notFinished !== []) {
                // Phase FORCING
                $this->shutdownPhase = ShutdownPhase::FORCING;
                $this->shutdownContext = new ASKShutdownContext(
                    phase: ShutdownPhase::FORCING,
                    forced: true,
                    deadline: microtime(true) + $this->shutdownTimeout,
                );

                $this->logger->warning(
                    '[AsyncKernel] Graceful shutdown timeout — forcing stop: '
                    .implode(', ', $notFinished)
                );

                $this->forceShutdown();
            }
        } catch (ASKInterruptException $e) {
            $this->logger->warning(
                "[BREAK] [AsyncKernel::shutdown] FORCE break by {$e->source} Interrupt"
            );
        } finally {
            $this->shutdownPhase = ShutdownPhase::STOPPED;

            if ($notFinished !== []) {
                $this->logger->error(
                    '[BREAK] [AsyncKernel::shutdown] Async tasks of Daemons not finished correctly: '
                    .implode(', ', $notFinished)
                );
            }
        }
    }

    private function prepareAllDaemonsShutdown(): void
    {
        foreach ($this->daemons as $daemon) {
            if ($daemon instanceof ASKShutdownAware) {
                $daemon->prepareShutdown();
            }
        }
    }

    private function forceShutdown(): void
    {
        foreach ($this->tickables as $tickable) {
            if ($tickable instanceof ASKFiberScheduler) {
                $tickable->forceStop();
            }
        }
    }

    private function drainDaemonsByPriority(): array
    {
        $sorted = $this->daemons;

        usort($sorted, function (ASKDaemonContract $a, ASKDaemonContract $b): int {
            $priorityA = $a instanceof ASKShutdownAware ? $a->shutdownPriority() : 50;
            $priorityB = $b instanceof ASKShutdownAware ? $b->shutdownPriority() : 50;

            return $priorityB <=> $priorityA;
        });

        $globalDeadline = microtime(true) + $this->shutdownTimeout;
        $notFinished = [];
        $startTime = microtime(true);
        $daemonDeadlines = [];

        foreach ($sorted as $daemon) {
            $timeout = $daemon instanceof ASKShutdownAware
                ? $daemon->shutdownTimeout()
                : 5;
            $remaining = $globalDeadline - microtime(true);
            $daemonDeadlines[spl_object_id($daemon)] = microtime(true)
                + min($timeout, max($remaining, 0));
        }

        $activePool = $sorted;

        while ($activePool !== [] && microtime(true) < $globalDeadline) {
            $this->tickAll();

            $anyProgress = false;
            $stillActive = [];

            foreach ($activePool as $daemon) {
                $id = spl_object_id($daemon);

                if (microtime(true) >= $daemonDeadlines[$id]) {
                    if (!$daemon->shutdown($this->shutdownContext)) {
                        $notFinished[] = $daemon->name();
                    }

                    continue;
                }

                if (!$daemon->shutdown($this->shutdownContext)) {
                    $stillActive[] = $daemon;
                } else {
                    $anyProgress = true;
                }
            }

            $activePool = $stillActive;

            $allSchedulersIdle = true;
            foreach ($this->tickables as $tickable) {
                if ($tickable instanceof ASKFiberScheduler && !$tickable->isIdle()) {
                    $allSchedulersIdle = false;

                    break;
                }
            }

            if ($allSchedulersIdle && $activePool !== []) {
                usleep(10 * 1000);
            } elseif (!$anyProgress && $activePool !== []) {
                usleep(1 * 1000);
            }
        }

        foreach ($activePool as $daemon) {
            if (!$daemon->shutdown($this->shutdownContext)) {
                $notFinished[] = $daemon->name();
            }
        }

        $elapsed = microtime(true) - $startTime;
        if ($notFinished !== []) {
            $this->logger->info(
                "[AsyncKernel] drain completed in {$elapsed}s, not finished: "
                .implode(', ', $notFinished)
            );
        }

        return $notFinished;
    }

    private function tickAll(): void
    {
        $systemPressure = $this->computeSystemPressure();

        foreach ($this->tickables as $tickable) {
            try {
                $tickable->tick($systemPressure);
            } catch (ASKInterruptException $e) {
                throw $e;
            } catch (Throwable $e) {
                if ($tickable instanceof ASKDaemonContract) {
                    $tickable->onError($e);
                }
            }
        }
    }
}
