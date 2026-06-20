<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Daemons;

use BAGArt\AsyncKernel\ASKShutdownContext;
use BAGArt\AsyncKernel\Contracts\ASKProducerContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKDaemonContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
use BAGArt\AsyncKernel\Contracts\Daemons\WithASKTickableContract;
use Closure;
use Throwable;

final class ASKFnDaemon implements
    ASKDaemonContract,
    WithASKTickableContract,
    ASKProducerContract,
    ASKTickableContract
{
    private bool $shutdownComplete = false;
    private readonly string $name;
    private readonly array $tickableMemoize;

    public function __construct(
        private readonly ASKFnDaemonContext $daemonContext,
        private readonly Closure $fnProduce,
        private readonly ?Closure $fnCanProduce = null,
        private readonly ?Closure $fnTick = null,
        private readonly ?Closure $fnStartup = null,
        private readonly ?Closure $fnShutdown = null,
        private readonly ?Closure $fnError = null,
    ) {
        $this->name = $daemonContext->daemonName;
    }

    public function onError(Throwable $e): void
    {
        if ($this->fnError !== null) {
            ($this->fnError)(e: $e, context: $this->daemonContext);
        }
    }

    public function tick(int $systemPressure): void
    {
        if ($this->fnTick !== null) {
            ($this->fnTick)(context: $this->daemonContext);
        }
    }

    public function pressure(): int
    {
        return 0;
    }

    public function canProduce(): bool
    {
        if ($this->fnCanProduce !== null) {
            return ($this->fnCanProduce)(context: $this->daemonContext);
        }

        return true;
    }

    public function produce(int $systemPressure): void
    {
        ($this->fnProduce)(context: $this->daemonContext);
    }

    public function shutdown(ASKShutdownContext $context): bool
    {
        if ($this->shutdownComplete) {
            return true;
        }

        if ($this->fnShutdown) {
            $this->shutdownComplete = ($this->fnShutdown)(
                context: $this->daemonContext,
                shutdownContext: $context,
            );
        } else {
            $this->shutdownComplete = true;
        }

        if ($this->shutdownComplete) {
            $this->daemonContext->logger->info("[ASKFnDaemon::shutdown] {$this->name} stopped");
        } else {
            $this->daemonContext->logger->debug("[ASKFnDaemon::shutdown] {$this->name} not stopped yet");
        }

        return $this->shutdownComplete;
    }

    public function isIdle(): bool
    {
        foreach ($this->tickable() as $tickable) {
            if (!$tickable->isIdle()) {
                return false;
            }
        }

        return true;
    }

    public function queueSize(): int
    {
        $queueSize = 0;
        foreach ($this->tickable() as $tickable) {
            $queueSize += $tickable->queueSize();
        }

        return $queueSize;
    }

    public function startup(): void
    {
        if ($this->fnStartup !== null) {
            ($this->fnStartup)(context: $this->daemonContext);
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function tickable(): array
    {
        return $this->tickableMemoize ??= array_filter(
            [
                $this->daemonContext->scheduler,
            ]
        );
    }
}
