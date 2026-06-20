<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Contracts;

interface MetricsContract
{
    public function recordExecution(int $durationMs): void;

    public function recordFailure(): void;

    public function recordRetry(): void;

    public function recordZombieFound(): void;

    public function recordCompleted(): void;

    public function recordDedupHit(): void;

    public function recordDeadLetter(): void;

    public function activePartitionCount(): int;

    public function retryQueueSize(): int;

    public function pendingAckCount(): int;

    public function streamBacklog(string $partitionKey): int;

    public function partitionLag(): float;

    /**
     * @return array{jobsExecuted: int, jobsFailed: int, jobsRetried: int, jobsCompleted: int, zombiesFound: int, dedupHits: int, deadLetterCount: int, avgExecutionTimeMs: float, executionTimeHistogram: array, activePartitions: int, retryQueueSize: int, pendingAckCount: int, partitionLag: float}
     */
    public function snapshot(): array;
}
