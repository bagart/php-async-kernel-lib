<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Contracts\Queue;

interface PartitionStreamContract
{
    public function length(string $partitionKey): int;
}
