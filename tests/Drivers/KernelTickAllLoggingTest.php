<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\AsyncKernel;
use BAGArt\AsyncKernel\ASKShutdownContext;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKDaemonContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
use BAGArt\AsyncKernel\Enum\ExceptionPolicy;
use BAGArt\AsyncKernel\Enum\ShutdownPhase;
use BAGArt\AsyncKernel\Wrappers\ASKLogWrapper;

final class DrainPathRecordingLogger extends \Psr\Log\AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
    }
}

final class DrainPathPlainTickable implements ASKTickableContract
{
    public function __construct(
        private readonly AsyncKernel $kernel,
    ) {
    }

    public function tick(int $systemPressure): void
    {
        if ($this->kernel->shutdownPhase() !== ShutdownPhase::DRAINING) {
            return;
        }

        throw new RuntimeException('drain-path-tickable-failure');
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

final class DrainPathBlockingDaemon implements ASKDaemonContract, ASKTickableContract
{
    public int $shutdownCalls = 0;

    public function shutdown(ASKShutdownContext $context): bool
    {
        $this->shutdownCalls++;

        return false;
    }

    public function name(): string
    {
        return 'drain-path-blocking';
    }

    public function onError(Throwable $e): void
    {
    }

    public function startup(): void
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

final class DrainPathStopTickable implements ASKTickableContract
{
    private int $ticks = 0;

    public function __construct(
        private readonly AsyncKernel $kernel,
    ) {
    }

    public function tick(int $systemPressure): void
    {
        $this->ticks++;

        if ($this->ticks >= 1) {
            $this->kernel->stop('drain-path-logging-done');
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

describe('AsyncKernel tickAll non-daemon failure logging (Q8)', function () {
    it('logs plain tickable failures raised during drain', function () {
        $logger = new DrainPathRecordingLogger();

        $kernel = new AsyncKernel(
            logger: new ASKLogWrapper(logger: $logger),
            exceptionPolicy: ExceptionPolicy::IGNORE,
            shutdownTimeout: 1,
            drainTimeout: 60,
        );

        $daemon = new DrainPathBlockingDaemon();

        $kernel->addDaemon($daemon);
        $kernel->addTickable(new DrainPathPlainTickable($kernel));
        $kernel->addTickable(new DrainPathStopTickable($kernel));

        $kernel->run();

        $logged = false;

        foreach ($logger->records as $record) {
            if (
                $record['level'] === 'error'
                && str_contains($record['message'], 'DrainPathPlainTickable')
                && str_contains($record['message'], 'drain-path-tickable-failure')
            ) {
                $logged = true;

                break;
            }
        }

        expect($logged)->toBeTrue()
            ->and($daemon->shutdownCalls)->toBeGreaterThan(0);
    });
});
