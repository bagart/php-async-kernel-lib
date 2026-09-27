<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Contracts\Queue;

interface ASKQueueAdapterContract
{
    public function push(string $queueName, string $payload): void;

    public function pop(string $queueName): ?string;

    public function size(string $queueName): int;
}
