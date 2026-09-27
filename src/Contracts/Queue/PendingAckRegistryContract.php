<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Contracts\Queue;

interface PendingAckRegistryContract
{
    /**
     * @return list<string>
     */
    public function getPartitions(): array;

    /**
     * @return array<string, string>
     */
    public function getPending(string $partitionKey): array;
}
