<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel;

use BAGArt\AsyncKernel\Contracts\MetricsContract;
use BAGArt\AsyncKernel\Contracts\Queue\ActivePartitionsContract;
use BAGArt\AsyncKernel\Contracts\Queue\PartitionStreamContract;
use BAGArt\AsyncKernel\Contracts\Queue\PendingAckRegistryContract;
use BAGArt\AsyncKernel\Contracts\Queue\RetryQueueSizeContract;

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
        private readonly RetryQueueSizeContract $retryQueue,
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
        return $this->retryQueue->retryQueueSize();
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

    /**
     * Returns a snapshot of all metrics as an associative array.
     *
     * Fiber-safety assumption: this method is safe only under a cooperative scheduler
     * (fibers as driven by the async kernel), where a running fiber is never
     * preempted in the middle of a statement and can only yield at an explicit
     * suspension point. The counters are plain int properties updated with
     * `++`/`+=` and never read under a lock, so as long as the snapshot is built
     * without hitting a suspension point, no other fiber can interleave between
     * the individual reads and the whole array describes one consistent point
     * in time.
     *
     * All local counter reads are plain property reads; the derived gauges
     * (`avgExecutionTimeMs`, `executionTimeHistogram`) are computed from those
     * same counters within this single call. The queue-side gauges
     * (`activePartitions`, `retryQueueSize`, `pendingAckCount`, `partitionLag`)
     * delegate to the injected contracts: if any of those implementations
     * suspends (e.g. performs blocking I/O inside a fiber) while the snapshot is
     * built, other fibers may mutate the local counters in between and the
     * local gauges and queue gauges may then describe slightly different points
     * in time.
     *
     * Under preemptive concurrency (threads, or async signal handlers
     * interrupting between the read and the `++`/`+=` update of a counter) the
     * returned values may be torn or stale. This method performs no locking and
     * makes no atomicity guarantee there.
     *
     * No external state or mutable references are returned.
     *
     * @return array<string, mixed>
     */
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
