<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\AsyncKernel;
use BAGArt\AsyncKernel\Exceptions\ASKInterruptException;
use BAGArt\AsyncKernel\ASKShutdownContext;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKDaemonContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
use BAGArt\AsyncKernel\Enum\ExceptionPolicy;
use BAGArt\AsyncKernel\Wrappers\ASKLogWrapper;
use Psr\Log\NullLogger;

final class ThrowingDaemonTickable implements ASKTickableContract, ASKDaemonContract
{
    public int $ticks = 0;

    /** @var list<Throwable> */
    public array $errors = [];

    public function __construct(
        private readonly ?Throwable $throwable = null,
        private readonly ?AsyncKernel $kernel = null,
    ) {
    }

    public function tick(int $systemPressure): void
    {
        $this->ticks++;

        if ($this->throwable !== null) {
            throw $this->throwable;
        }

        $this->kernel?->stop('contract-test');
    }

    public function onError(Throwable $e): void
    {
        $this->errors[] = $e;
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
        return 'throwing-daemon-tickable';
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

final class PlainThrowingTickable implements ASKTickableContract
{
    public function tick(int $systemPressure): void
    {
        throw new RuntimeException('plain tickable failure');
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

describe('ASKTickableContract pressure semantics (06 §44–§46)', function () {
    it('routes daemon tickable failures to onError and keeps the loop alive', function () {
        $kernel = new AsyncKernel(
            logger: new ASKLogWrapper(logger: new NullLogger()),
            exceptionPolicy: ExceptionPolicy::IGNORE,
        );

        $failing = new ThrowingDaemonTickable(throwable: new RuntimeException('transient under load'));
        $healthy = new ThrowingDaemonTickable(kernel: $kernel);

        $kernel->addTickable($failing);
        $kernel->addTickable($healthy);

        $kernel->run();

        expect($failing->ticks)->toBeGreaterThan(0)
            ->and($failing->errors)->toHaveCount(1)
            ->and($failing->errors[0])->toBeInstanceOf(RuntimeException::class)
            ->and($healthy->ticks)->toBeGreaterThan(0);
    });

    it('propagates failures of plain tickables when the policy demands interruption', function () {
        $kernel = new AsyncKernel(
            logger: new ASKLogWrapper(logger: new NullLogger()),
            exceptionPolicy: ExceptionPolicy::INTERRUPT,
        );

        $kernel->addTickable(new PlainThrowingTickable());

        $kernel->run();
    })->throws(RuntimeException::class, 'plain tickable failure');

    it('never swallows ASKInterruptException raised by a daemon tickable', function () {
        $kernel = new AsyncKernel(
            logger: new ASKLogWrapper(logger: new NullLogger()),
            exceptionPolicy: ExceptionPolicy::IGNORE,
        );

        $interrupting = new ThrowingDaemonTickable(throwable: new ASKInterruptException('force'));
        $after = new ThrowingDaemonTickable();

        $kernel->addTickable($interrupting);
        $kernel->addTickable($after);

        $kernel->run();

        expect($interrupting->errors)->toBeEmpty()
            ->and($after->ticks)->toBe(0);
    });
});
