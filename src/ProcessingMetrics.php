<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel;

use BAGArt\ASKClient\Contracts\Queue\ActivePartitionsContract;
use BAGArt\ASKClient\Contracts\Queue\ASKQueueAdapterContract;
use BAGArt\ASKClient\Contracts\Queue\PartitionStreamContract;
use BAGArt\ASKClient\Contracts\Queue\PendingAckRegistryContract;
use BAGArt\AsyncKernel\Contracts\MetricsContract;

final class ProcessingMetrics implements MetricsContract
{
    private int $jobsExecuted = 0;

    private int $jobsFailed = 0;

    private int $jobsRetried = 0;

    private int $jobsCompleted = 0;

    private int $zombiesFound = 0;

    private int $dedupHits = 0;

    private int $deadLetterCount = 0;

    private int $totalExecutionTimeMs = 0;

    private int $executionCount = 0;

    /** @var array<int, int> */
    private array $executionTimeBuckets = [];

    public function __construct(
        private readonly ActivePartitionsContract $activePartitions,
        private readonly ASKQueueAdapterContract $retryQueue,
        private readonly ?PendingAckRegistryContract $pendingAck = null,
        private readonly ?PartitionStreamContract $stream = null,
    ) {
    }

    public function recordExecution(int $durationMs): void
    {
        ++$this->jobsExecuted;
        $this->totalExecutionTimeMs += $durationMs;
        ++$this->executionCount;

        $bucket = match (true) {
            $durationMs < 10 => 0,
            $durationMs < 50 => 1,
            $durationMs < 100 => 2,
            $durationMs < 500 => 3,
            $durationMs < 1000 => 4,
            $durationMs < 5000 => 5,
            default => 6,
        };

        $this->executionTimeBuckets[$bucket] = ($this->executionTimeBuckets[$bucket] ?? 0) + 1;
    }

    public function recordFailure(): void
    {
        ++$this->jobsFailed;
    }

    public function recordRetry(): void
    {
        ++$this->jobsRetried;
    }

    public function recordZombieFound(): void
    {
        ++$this->zombiesFound;
    }

    public function recordCompleted(): void
    {
        ++$this->jobsCompleted;
    }

    public function recordDedupHit(): void
    {
        ++$this->dedupHits;
    }

    public function recordDeadLetter(): void
    {
        ++$this->deadLetterCount;
    }

    public function activePartitionCount(): int
    {
        return $this->activePartitions->count();
    }

    public function retryQueueSize(): int
    {
        return $this->retryQueue->size();
    }

    public function pendingAckCount(): int
    {
        if ($this->pendingAck === null) {
            return 0;
        }

        $count = 0;

        foreach ($this->pendingAck->getPartitions() as $partition) {
            $count += count($this->pendingAck->getPending($partition));
        }

        return $count;
    }

    public function streamBacklog(string $partitionKey): int
    {
        if ($this->stream === null) {
            return 0;
        }

        return $this->stream->length($partitionKey);
    }

    public function partitionLag(): float
    {
        if ($this->pendingAck === null) {
            return 0.0;
        }

        $partitions = $this->pendingAck->getPartitions();

        if ($partitions === []) {
            return 0.0;
        }

        $totalLag = 0;
        $count = 0;

        foreach ($partitions as $partition) {
            $pending = $this->pendingAck->getPending($partition);
            if ($pending !== []) {
                $totalLag += count($pending);
                ++$count;
            }
        }

        return $count > 0 ? $totalLag / $count : 0.0;
    }

    public function executionTimeHistogram(): array
    {
        return [
            'lt10ms' => $this->executionTimeBuckets[0] ?? 0,
            '10_50ms' => $this->executionTimeBuckets[1] ?? 0,
            '50_100ms' => $this->executionTimeBuckets[2] ?? 0,
            '100_500ms' => $this->executionTimeBuckets[3] ?? 0,
            '500_1000ms' => $this->executionTimeBuckets[4] ?? 0,
            '1_5s' => $this->executionTimeBuckets[5] ?? 0,
            'gt5s' => $this->executionTimeBuckets[6] ?? 0,
        ];
    }

    public function averageExecutionTimeMs(): float
    {
        if ($this->executionCount === 0) {
            return 0.0;
        }

        return $this->totalExecutionTimeMs / $this->executionCount;
    }

    public function snapshot(): array
    {
        return [
            'jobsExecuted' => $this->jobsExecuted,
            'jobsFailed' => $this->jobsFailed,
            'jobsRetried' => $this->jobsRetried,
            'jobsCompleted' => $this->jobsCompleted,
            'zombiesFound' => $this->zombiesFound,
            'dedupHits' => $this->dedupHits,
            'deadLetterCount' => $this->deadLetterCount,
            'avgExecutionTimeMs' => $this->averageExecutionTimeMs(),
            'executionTimeHistogram' => $this->executionTimeHistogram(),
            'activePartitions' => $this->activePartitionCount(),
            'retryQueueSize' => $this->retryQueueSize(),
            'pendingAckCount' => $this->pendingAckCount(),
            'partitionLag' => $this->partitionLag(),
        ];
    }
}
