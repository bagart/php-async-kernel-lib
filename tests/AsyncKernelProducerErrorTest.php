<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\AsyncKernel;
use BAGArt\AsyncKernel\ASKShutdownContext;
use BAGArt\AsyncKernel\Contracts\ASKProducerContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKDaemonContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
use BAGArt\AsyncKernel\Enum\ExceptionPolicy;
use BAGArt\AsyncKernel\Exceptions\ASKInterruptException;
use BAGArt\AsyncKernel\Wrappers\ASKLogWrapper;
use Psr\Log\NullLogger;

/**
 * Producer whose produce() suspends once, then throws on resume —
 * reproducing the long-poll path that caused the FiberError fatal.
 */
final class ThrowingOnResumeProducer implements ASKProducerContract, ASKDaemonContract
{
    public ?Throwable $caught = null;

    public int $produceCalls = 0;

    public ?Throwable $resumeThrown = null;

    private ?\Fiber $suspendedFiber = null;

    public function __construct(
        private readonly Throwable $throw,
    ) {
    }

    public function canProduce(): bool
    {
        return $this->produceCalls === 0;
    }

    public function produce(int $systemPressure): void
    {
        $this->produceCalls++;
        $this->suspendedFiber = \Fiber::getCurrent();
        \Fiber::suspend();
        throw $this->throw;
    }

    public function pressure(): int
    {
        return 0;
    }

    public function onError(Throwable $e): void
    {
        $this->caught = $e;
    }

    public function startup(): void
    {
    }

    public function shutdown(ASKShutdownContext $context): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'ThrowingOnResumeProducer';
    }

    public function hasSuspendedFiber(): bool
    {
        return $this->suspendedFiber !== null && $this->suspendedFiber->isSuspended();
    }

    /**
     * Resumes the suspended producer fiber so it can throw on the next tick.
     * The throw is absorbed here — mirroring the promise layer (ASKPromise
     * catches throws from resumed fibers and rejects a child). This leaves
     * the fiber terminated-while-throwing, which is exactly what the kernel's
     * producer drain loop must handle.
     */
    public function resumeSuspendedFiber(): void
    {
        if ($this->suspendedFiber !== null && $this->suspendedFiber->isSuspended()) {
            try {
                $this->suspendedFiber->resume();
            } catch (Throwable $e) {
                $this->resumeThrown = $e;
            }
        }
    }
}

/**
 * Tickable that resumes the producer's suspended fiber once, then stops the kernel.
 */
final class ResumeAndStopTickable implements ASKTickableContract
{
    private bool $resumed = false;

    public function __construct(
        private readonly ThrowingOnResumeProducer $producer,
        private readonly AsyncKernel $kernel,
    ) {
    }

    public function tick(int $systemPressure): void
    {
        if (!$this->resumed && $this->producer->hasSuspendedFiber()) {
            $this->resumed = true;
            $this->producer->resumeSuspendedFiber();

            return;
        }

        if ($this->resumed) {
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

describe('AsyncKernel producer error handling', function () {
    it('routes the original exception to onError when produce() throws after resume', function () {
        $kernel = new AsyncKernel(
            logger: new ASKLogWrapper(logger: new NullLogger()),
            exceptionPolicy: ExceptionPolicy::IGNORE,
        );

        $marker = new RuntimeException('poller-resume-failure');
        $producer = new ThrowingOnResumeProducer(throw: $marker);
        $kernel->addDaemon($producer);
        $kernel->addTickable(new ResumeAndStopTickable(producer: $producer, kernel: $kernel));

        $kernel->run();

        expect($producer->caught)
            ->not->toBeNull()
            ->and($producer->caught)->toBe($marker)
            ->and($producer->caught->getMessage())->toBe('poller-resume-failure')
            ->and($producer->caught)->not->toBeInstanceOf(\FiberError::class);
    });

    it('lets ASKInterruptException thrown from produce() bubble (not routed to onError)', function () {
        $records = [];
        $spyLogger = new class($records) implements \Psr\Log\LoggerInterface {
            /** @param list<array{level: string, message: string}> $records */
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
        );

        $interrupt = new ASKInterruptException(source: 'test-source', message: 'interrupt-from-producer');
        $producer = new ThrowingOnResumeProducer(throw: $interrupt);
        $kernel->addDaemon($producer);
        $kernel->addTickable(new ResumeAndStopTickable(producer: $producer, kernel: $kernel));

        // ASKInterruptException bubbles to run()'s handler, which stops the kernel cleanly.
        // run() returns (does not throw); the key proof is onError was NOT called.
        $kernel->run();

        expect($producer->caught)->toBeNull()
            ->and($records)->not->toBeEmpty();

        $interruptLogged = false;
        foreach ($records as $record) {
            if (str_contains($record['message'], 'test-source') && str_contains($record['message'], 'Interrupt')) {
                $interruptLogged = true;

                break;
            }
        }
        expect($interruptLogged)->toBeTrue();
    });
});
