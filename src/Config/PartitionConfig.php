<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Config;

use BAGArt\AsyncKernel\Partition\RetryIntensityEnum;

final class PartitionConfig
{
    public function __construct(
        public int $partitionTtlSeconds = 30,
        public int $lockTtlSeconds = 30,
        public int $lockRenewInterval = 10,
        public int $dedupTtlSeconds = 86400,
        public int $dedupPermanentTtl = 0,
        public int $streamMaxLen = 10000,
        public int $idleSleepMicroseconds = 1_000_000,
        public int $busySleepMicroseconds = 100,
        public RetryIntensityEnum $retryIntensity = RetryIntensityEnum::MEDIUM,
        public int $maxAttempts = 5,
        public int $retryBatchSize = 50,
        public int $retryFenceTtlSeconds = 300,
        public int $trimBatchSize = 50,
        public int $trimIntervalSeconds = 60,
        public int $backpressureDelaySeconds = 5,
        public int $backpressureRetryThreshold = 5000,
        public int $backpressureZombieThreshold = 10,
        public int $penaltyDecayIntervalSeconds = 60,
        public float $penaltyDecayFactor = 0.9,
        public int $zombieScanIntervalSeconds = 30,
        public int $zombieBatchSize = 20,
        public int $workerHeartbeatTtlSeconds = 60,
        public int $jobTimeoutSeconds = 300,
    ) {
    }

    public function retryDelay(int $attempt): int
    {
        $backoff = $this->retryIntensity->backoffSeconds();
        $index = min($attempt, count($backoff) - 1);

        return $backoff[$index] ?? end($backoff);
    }
}
