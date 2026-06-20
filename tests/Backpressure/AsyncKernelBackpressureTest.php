<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\AsyncKernel;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
use BAGArt\AsyncKernel\Contracts\ASKProducerContract;
use BAGArt\AsyncKernel\Enum\ExceptionPolicy;
use BAGArt\AsyncKernel\Wrappers\ASKLogWrapper;
use Psr\Log\NullLogger;

final class PressureCapturingTickable implements ASKTickableContract
{
    public int $lastSystemPressure = -1;

    public function __construct(
        private readonly int $pressure = 0,
        private readonly ?AsyncKernel $kernel = null,
    ) {
    }

    public function tick(int $systemPressure): void
    {
        $this->lastSystemPressure = $systemPressure;
        $this->kernel?->stop('test-capture');
    }

    public function pressure(): int
    {
        return $this->pressure;
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

final class PressureCapturingProducer implements ASKProducerContract
{
    public int $lastSystemPressure = -1;

    public function __construct(
        private readonly int $pressure = 0,
    ) {
    }

    public function canProduce(): bool
    {
        return true;
    }

    public function produce(int $systemPressure): void
    {
        $this->lastSystemPressure = $systemPressure;
    }

    public function pressure(): int
    {
        return $this->pressure;
    }
}

describe('AsyncKernel backpressure', function () {
    it('computes systemPressure as max of all component pressures and passes to tickables', function () {
        $kernel = new AsyncKernel(
            logger: new ASKLogWrapper(logger: new NullLogger()),
            exceptionPolicy: ExceptionPolicy::IGNORE,
        );

        $tickable1 = new PressureCapturingTickable(pressure: 30);
        $tickable2 = new PressureCapturingTickable(pressure: 80);
        $stopTickable = new PressureCapturingTickable(pressure: 10, kernel: $kernel);

        $kernel->addTickable($tickable1);
        $kernel->addTickable($tickable2);
        $kernel->addTickable($stopTickable);

        $kernel->run();

        expect($tickable1->lastSystemPressure)->toBe(80)
            ->and($tickable2->lastSystemPressure)->toBe(80)
            ->and($stopTickable->lastSystemPressure)->toBe(80);
    });

    it('tickables with mixed pressures all receive the max', function () {
        $kernel = new AsyncKernel(
            logger: new ASKLogWrapper(logger: new NullLogger()),
            exceptionPolicy: ExceptionPolicy::IGNORE,
        );

        $a = new PressureCapturingTickable(pressure: 0);
        $b = new PressureCapturingTickable(pressure: 50);
        $stopTickable = new PressureCapturingTickable(pressure: 200, kernel: $kernel);

        $kernel->addTickable($a);
        $kernel->addTickable($b);
        $kernel->addTickable($stopTickable);

        $kernel->run();

        expect($a->lastSystemPressure)->toBe(200)
            ->and($b->lastSystemPressure)->toBe(200)
            ->and($stopTickable->lastSystemPressure)->toBe(200);
    });
});
