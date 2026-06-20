<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Daemons;

use BAGArt\AsyncKernel\ASKShutdownContext;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKDaemonContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableEngineContract;
use BAGArt\AsyncKernel\Contracts\Daemons\WithASKTickableContract;
use BAGArt\AsyncKernel\Wrappers\ASKLogWrapper;
use Throwable;

final class ASKExecutionDaemon implements ASKDaemonContract, WithASKTickableContract
{
    private bool $isShuttingDown = false;

    public function __construct(
        private readonly ASKTickableEngineContract $engine,
        private readonly ASKLogWrapper $logger,
        private readonly string $name = 'ASKExecutionDaemon',
        private readonly string $queueName = 'ask-execution',
    ) {
    }

    public function shutdown(ASKShutdownContext $context): bool
    {
        if (!$this->isShuttingDown) {
            $this->isShuttingDown = true;
            $this->logger->info("[{$this->name()}] shutdown initiated.");
        }

        return $this->engine->shutdown($context);
    }

    public function onError(Throwable $e): void
    {
        $this->logger->error(
            "[{$this->name()}] error: {$e->getMessage()}",
            ['exception' => $e::class]
        );
    }

    public function startup(): void
    {
        $this->logger->info(
            "[{$this->name()}] started. Queue: {$this->queueName}"
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    public function tickable(): array
    {
        return [$this->engine];
    }
}
