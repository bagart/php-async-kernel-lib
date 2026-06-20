<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Job;

use BAGArt\AsyncKernel\Config\PartitionConfig;
use Throwable;

final class JobLifetimePolicy
{
    public function __construct(
        private readonly PartitionConfig $config,
    ) {
    }

    public function maxAttempts(): int
    {
        return $this->config->maxAttempts;
    }

    public function isExpired(AsyncJob $job): bool
    {
        return $job->attempt >= $this->config->maxAttempts;
    }

    public function shouldRetry(AsyncJob $job, Throwable $e): bool
    {
        if ($this->isExpired($job)) {
            return false;
        }

        return true;
    }

    public function nextRetryAt(AsyncJob $job): int
    {
        $delay = $this->config->retryDelay($job->attempt + 1);

        return time() + $delay;
    }
}
