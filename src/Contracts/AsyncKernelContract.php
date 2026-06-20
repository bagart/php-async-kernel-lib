<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Contracts;

use BAGArt\AsyncKernel\Contracts\Daemons\ASKDaemonContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;

interface AsyncKernelContract
{
    public function addDaemon(ASKDaemonContract $daemon, int $producerProducerInterval = 0): self;

    public function addTickable(?ASKTickableContract $tickable): self;

    public function run(): void;

    public function stop(string $reason = 'break'): void;

    public function tick(): bool;
}
