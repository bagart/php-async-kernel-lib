<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Job;

class AsyncJob
{
    public function __construct(
        public readonly string $jobId,
        public readonly ?string $partitionKey,
        public readonly string $processor,
        public readonly ?string $executionKey,
        public readonly int $createdAt,
        public int $attempt = 0,
        public ?int $retryAt = null,
        public array $payload = [],
    ) {
    }
}
