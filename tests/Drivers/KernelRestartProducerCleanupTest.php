<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\AsyncKernel;
use BAGArt\AsyncKernel\ASKShutdownContext;
use BAGArt\AsyncKernel\Contracts\ASKProducerContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKDaemonContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
use BAGArt\AsyncKernel\Enum\ExceptionPolicy;
use BAGArt\AsyncKernel\Wrappers\ASKLogWrapper;
use Psr\Log\NullLogger;

final class RestartCleanupProducerDaemon implements ASKDaemonContract, ASKTickableContract, ASKProducerContract
{
    private int $ticks = 0;

    public function __construct(
        private readonly AsyncKernel $kernel,
    ) {
    }

    public function tick(int $systemPressure): void
    {
        $this->ticks++;

        if ($this->ticks === 1) {
            throw new RuntimeException('restart-trigger');
        }

        $this->kernel->stop('restart-cleanup-pin-done');
    }

    public function canProduce(): bool
    {
        return false;
    }

    public function produce(int $systemPressure): void
    {
    }

    public function pressure(): int
    {
        return 0;
    }

    public function onError(Throwable $e): void
    {
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
        return 'restart-cleanup-pin';
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

describe('AsyncKernel restartDaemon producer cleanup (Q7)', function () {
    it('clears producerFibers and producerFiberErrors for the restarted producer', function () {
        $kernel = new AsyncKernel(
            logger: new ASKLogWrapper(logger: new NullLogger()),
            exceptionPolicy: ExceptionPolicy::RESTART_DAEMON,
            shutdownTimeout: 1,
            drainTimeout: 100,
        );

        $daemon = new RestartCleanupProducerDaemon($kernel);
        $kernel->addDaemon($daemon);

        $producerId = spl_object_id($daemon);

        $fiberProperty = new ReflectionProperty(AsyncKernel::class, 'producerFibers');
        $staleFibers = $fiberProperty->getValue($kernel);
        $staleFibers[$producerId] = new Fiber(static function (): void {
            Fiber::suspend();
        });
        $fiberProperty->setValue($kernel, $staleFibers);

        $errorProperty = new ReflectionProperty(AsyncKernel::class, 'producerFiberErrors');
        $staleErrors = $errorProperty->getValue($kernel);
        $staleErrors[$producerId] = new RuntimeException('stale-producer-error');
        $errorProperty->setValue($kernel, $staleErrors);

        $kernel->run();

        expect(array_key_exists($producerId, $fiberProperty->getValue($kernel)))->toBeFalse()
            ->and(array_key_exists($producerId, $errorProperty->getValue($kernel)))->toBeFalse();
    });
});
