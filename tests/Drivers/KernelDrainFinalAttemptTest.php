<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\AsyncKernel;
use BAGArt\AsyncKernel\ASKShutdownContext;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKDaemonContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
use BAGArt\AsyncKernel\Enum\ExceptionPolicy;
use BAGArt\AsyncKernel\Wrappers\ASKLogWrapper;
use Psr\Log\NullLogger;

final class DrainAttemptCountingDaemon implements ASKDaemonContract, ASKTickableContract
{
    public int $shutdownCalls = 0;

    public function startup(): void
    {
    }

    public function shutdown(ASKShutdownContext $context): bool
    {
        $this->shutdownCalls++;

        return true;
    }

    public function name(): string
    {
        return 'drain-attempt-pin';
    }

    public function onError(Throwable $e): void
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

final class DrainAttemptStopTickable implements ASKTickableContract
{
    private int $ticks = 0;

    public function __construct(
        private readonly AsyncKernel $kernel,
        private readonly int $stopAfter = 1,
    ) {
    }

    public function tick(int $systemPressure): void
    {
        $this->ticks++;

        if ($this->ticks >= $this->stopAfter) {
            $this->kernel->stop('drain-attempt-pin-done');
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

describe('AsyncKernel drain final attempt (Q1)', function () {
    it('skips the final shutdown() call once the drain deadline has passed', function () {
        $records = [];
        $spyLogger = new class ($records) implements \Psr\Log\LoggerInterface {
            public function __construct(private array &$records)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
            }

            public function emergency(string|\Stringable $message, array $context = []): void
            {
            }

            public function alert(string|\Stringable $message, array $context = []): void
            {
            }

            public function critical(string|\Stringable $message, array $context = []): void
            {
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
            }

            public function info(string|\Stringable $message, array $context = []): void
            {
                $this->log('info', $message, $context);
            }

            public function debug(string|\Stringable $message, array $context = []): void
            {
            }
        };

        $kernel = new AsyncKernel(
            logger: new ASKLogWrapper(logger: $spyLogger),
            exceptionPolicy: ExceptionPolicy::IGNORE,
            shutdownTimeout: 1,
            drainTimeout: 0,
        );

        $daemon = new DrainAttemptCountingDaemon();
        $kernel->addDaemon($daemon);
        $kernel->addTickable(new DrainAttemptStopTickable(kernel: $kernel));

        $kernel->run();

        expect($daemon->shutdownCalls)->toBe(0);

        $notFinishedLogged = false;

        foreach ($records as $record) {
            if ($record['level'] === 'error' && str_contains($record['message'], 'drain-attempt-pin')) {
                $notFinishedLogged = true;

                break;
            }
        }

        expect($notFinishedLogged)->toBeTrue();
    });

    it('calls shutdown() once during a drain that finishes inside the deadline', function () {
        $kernel = new AsyncKernel(
            logger: new ASKLogWrapper(logger: new NullLogger()),
            exceptionPolicy: ExceptionPolicy::IGNORE,
            shutdownTimeout: 5,
            drainTimeout: 30_000,
        );

        $daemon = new DrainAttemptCountingDaemon();
        $kernel->addDaemon($daemon);
        $kernel->addTickable(new DrainAttemptStopTickable(kernel: $kernel));

        $kernel->run();

        expect($daemon->shutdownCalls)->toBe(1);
    });
});
