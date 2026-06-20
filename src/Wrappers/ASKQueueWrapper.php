<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Wrappers;

use BAGArt\AsyncKernel\Contracts\Queue\ASKQueueAdapterContract;

final class ASKQueueWrapper
{
    public function __construct(
        private readonly ASKQueueAdapterContract $queue,
    ) {
    }

    public function push(string $queue, string $payload): void
    {
        $this->queue->push($queue, $payload);
    }

    public function pop(string $queue): ?string
    {
        return $this->queue->pop($queue);
    }

    public function len(string $queue): int
    {
        return $this->queue->size($queue);
    }
}
