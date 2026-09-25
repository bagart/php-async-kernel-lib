<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\AsyncKernel;
use BAGArt\AsyncKernel\ASKShutdownContext;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKDaemonContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKShutdownAware;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
use BAGArt\AsyncKernel\Enum\ExceptionPolicy;
use BAGArt\AsyncKernel\Enum\ShutdownPhase;
use BAGArt\AsyncKernel\Wrappers\ASKLogWrapper;
use Psr\Log\NullLogger;

final class ThrowingShutdownDaemon implements ASKDaemonContract, ASKShutdownAware, ASKTickableContract
{
    public function __construct(
        private readonly string $daemonName,
        private readonly ?Throwable $throwOnShutdown = null,
        private readonly int $priority = 50,
    ) {
    }

    public function startup(): void
    {
    }

    public function shutdown(ASKShutdownContext $context): bool
    {
        if ($this->throwOnShutdown !== null) {
            throw $this->throwOnShutdown;
        }

        return true;
    }

    public function name(): string
    {
        return $this->daemonName;
    }

    public function onError(Throwable $e): void
    {
    }

    public function shutdownPriority(): int
    {
        return $this->priority;
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

final class StopKernelTickable implements ASKTickableContract
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
            $this->kernel->stop('test-done');
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

describe('AsyncKernel shutdown daemon exception handling', function () {
    it('continues draining when one daemon throws during shutdown', function () {
        $records = [];
        $spyLogger = new class ($records) implements \Psr\Log\LoggerInterface
        {
            public function __construct(private array &$records)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
            }

            public function emergency(string|\Stringable $message, array $context = []): void
            {
                $this->log('emergency', $message, $context);
            }

            public function alert(string|\Stringable $message, array $context = []): void
            {
                $this->log('alert', $message, $context);
            }

            public function critical(string|\Stringable $message, array $context = []): void
            {
                $this->log('critical', $message, $context);
            }

            public function error(string|\Stringable $message, array $context = []): void
            {
                $this->log('error', $message, $context);
            }

            public function warning(string|\Stringable $message, array $context = []): void
            {
                $this->log('warning', $message, $context);
            }

            public function notice(string|\Stringable $message, array $context = []): void
            {
                $this->log('notice', $message, $context);
            }

            public function info(string|\Stringable $message, array $context = []): void
            {
                $this->log('info', $message, $context);
            }

            public function debug(string|\Stringable $message, array $context = []): void
            {
                $this->log('debug', $message, $context);
            }
        };

        $kernel = new AsyncKernel(
            logger: new ASKLogWrapper(logger: $spyLogger),
            exceptionPolicy: ExceptionPolicy::IGNORE,
            shutdownTimeout: 1,
        );

        $goodDaemon = new ThrowingShutdownDaemon('good-daemon', priority: 100);
        $badDaemon = new ThrowingShutdownDaemon(
            'bad-daemon',
            throwOnShutdown: new RuntimeException('daemon-crash'),
            priority: 50,
        );

        $kernel->addDaemon($goodDaemon);
        $kernel->addDaemon($badDaemon);
        $kernel->addTickable(new StopKernelTickable(kernel: $kernel, stopAfter: 2));

        $kernel->run();

        $errorLogged = false;
        foreach ($records as $record) {
            if ($record['level'] === 'error' && str_contains($record['message'], 'bad-daemon')) {
                $errorLogged = true;

                break;
            }
        }

        expect($errorLogged)->toBeTrue();
    });

    it('kernel reaches STOPPED phase even when daemon throws in shutdown', function () {
        $kernel = new AsyncKernel(
            logger: new ASKLogWrapper(logger: new NullLogger()),
            exceptionPolicy: ExceptionPolicy::IGNORE,
            shutdownTimeout: 1,
        );

        $badDaemon = new ThrowingShutdownDaemon(
            'crash-daemon',
            throwOnShutdown: new RuntimeException('boom'),
        );

        $kernel->addDaemon($badDaemon);
        $kernel->addTickable(new StopKernelTickable(kernel: $kernel, stopAfter: 2));

        $kernel->run();

        expect($kernel->shutdownPhase())->toBe(ShutdownPhase::STOPPED);
    });

    it('does not crash when multiple daemons throw during shutdown', function () {
        $kernel = new AsyncKernel(
            logger: new ASKLogWrapper(logger: new NullLogger()),
            exceptionPolicy: ExceptionPolicy::IGNORE,
            shutdownTimeout: 1,
        );

        $kernel->addDaemon(new ThrowingShutdownDaemon(
            'crash-1',
            throwOnShutdown: new RuntimeException('first'),
            priority: 100,
        ));
        $kernel->addDaemon(new ThrowingShutdownDaemon(
            'crash-2',
            throwOnShutdown: new RuntimeException('second'),
            priority: 50,
        ));
        $kernel->addTickable(new StopKernelTickable(kernel: $kernel, stopAfter: 2));

        $kernel->run();

        expect($kernel->shutdownPhase())->toBe(ShutdownPhase::STOPPED);
    });
});
