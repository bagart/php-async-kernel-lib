<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\Contracts\MetricsContract;
use BAGArt\AsyncKernel\Contracts\Queue\ActivePartitionsContract;
use BAGArt\AsyncKernel\Contracts\Queue\PartitionStreamContract;
use BAGArt\AsyncKernel\Contracts\Queue\PendingAckRegistryContract;
use BAGArt\AsyncKernel\Contracts\Queue\RetryQueueSizeContract;
use BAGArt\AsyncKernel\ProcessingMetrics;

final class QueueContractsCountedPartitions implements ActivePartitionsContract
{
    public function __construct(private readonly int $total = 0)
    {
    }

    public function count(): int
    {
        return $this->total;
    }
}

final class QueueContractsSizedQueue implements RetryQueueSizeContract
{
    public int $retryQueueSizeCalls = 0;

    public function __construct(private readonly int $total = 0)
    {
    }

    public function retryQueueSize(): int
    {
        ++$this->retryQueueSizeCalls;

        return $this->total;
    }
}

final class QueueContractsMapPendingAck implements PendingAckRegistryContract
{
    /** @param array<string, array<string, string>> $pending */
    public function __construct(private readonly array $pending = [])
    {
    }

    public function getPartitions(): array
    {
        return array_keys($this->pending);
    }

    public function getPending(string $partitionKey): array
    {
        return $this->pending[$partitionKey] ?? [];
    }
}

final class QueueContractsFixedStream implements PartitionStreamContract
{
    /** @param array<string, int> $lengths */
    public function __construct(private readonly array $lengths = [])
    {
    }

    public function length(string $partitionKey): int
    {
        return $this->lengths[$partitionKey] ?? 0;
    }
}

/**
 * @param  array<string, array<string, string>>  $pending
 * @param  array<string, int>  $lengths
 */
function makeQueueContractsMetrics(
    int $partitions = 0,
    int $retrySize = 0,
    ?array $pending = null,
    ?array $lengths = null,
): ProcessingMetrics {
    return new ProcessingMetrics(
        new QueueContractsCountedPartitions($partitions),
        new QueueContractsSizedQueue($retrySize),
        $pending === null ? null : new QueueContractsMapPendingAck($pending),
        $lengths === null ? null : new QueueContractsFixedStream($lengths),
    );
}

describe('ProcessingMetrics over the kernel-local queue contracts (C2)', function () {
    it('loads and records the core counters', function () {
        $metrics = makeQueueContractsMetrics();

        $metrics->recordExecution(25);
        $metrics->recordExecution(75);
        $metrics->recordFailure();
        $metrics->recordRetry();
        $metrics->recordZombieFound();
        $metrics->recordCompleted();
        $metrics->recordDedupHit();
        $metrics->recordDeadLetter();

        $snapshot = $metrics->snapshot();

        expect($snapshot['jobsExecuted'])->toBe(2);
        expect($snapshot['jobsFailed'])->toBe(1);
        expect($snapshot['jobsRetried'])->toBe(1);
        expect($snapshot['zombiesFound'])->toBe(1);
        expect($snapshot['jobsCompleted'])->toBe(1);
        expect($snapshot['dedupHits'])->toBe(1);
        expect($snapshot['deadLetterCount'])->toBe(1);
        expect($snapshot['avgExecutionTimeMs'])->toBe(50.0);
    });

    it('is a MetricsContract implementation', function () {
        expect(makeQueueContractsMetrics())->toBeInstanceOf(MetricsContract::class);
    });

    it('buckets execution times into the histogram', function () {
        $metrics = makeQueueContractsMetrics();

        foreach ([5, 20, 60, 200, 600, 2000, 9000] as $durationMs) {
            $metrics->recordExecution($durationMs);
        }

        expect($metrics->executionTimeHistogram())->toBe([
            'lt10ms' => 1,
            '10_50ms' => 1,
            '50_100ms' => 1,
            '100_500ms' => 1,
            '500_1000ms' => 1,
            '1_5s' => 1,
            'gt5s' => 1,
        ]);
        expect($metrics->averageExecutionTimeMs())->toBe(11885 / 7);
    });

    it('returns 0.0 average before any execution is recorded', function () {
        expect(makeQueueContractsMetrics()->averageExecutionTimeMs())->toBe(0.0);
    });

    it('reads the queue-side gauges through the local contracts', function () {
        $metrics = makeQueueContractsMetrics(
            partitions: 3,
            retrySize: 2,
            pending: [
                'p1' => ['j1' => 'w1', 'j2' => 'w2'],
                'p2' => [],
                'p3' => ['j3' => 'w3'],
            ],
            lengths: ['p1' => 7],
        );

        expect($metrics->activePartitionCount())->toBe(3);
        expect($metrics->retryQueueSize())->toBe(2);
        expect($metrics->pendingAckCount())->toBe(3);
        expect($metrics->streamBacklog('p1'))->toBe(7);
        expect($metrics->streamBacklog('missing'))->toBe(0);
        expect($metrics->partitionLag())->toBe(1.5);
    });

    it('treats missing optional contracts as zero', function () {
        $metrics = new ProcessingMetrics(
            new QueueContractsCountedPartitions(0),
            new QueueContractsSizedQueue(),
        );

        expect($metrics->pendingAckCount())->toBe(0);
        expect($metrics->partitionLag())->toBe(0.0);
        expect($metrics->streamBacklog('p1'))->toBe(0);

        $snapshot = $metrics->snapshot();

        expect($snapshot['pendingAckCount'])->toBe(0);
        expect($snapshot['partitionLag'])->toBe(0.0);
        expect($snapshot['activePartitions'])->toBe(0);
        expect($snapshot['retryQueueSize'])->toBe(0);
    });

    it('returns 0.0 partition lag when no partition has pending acks', function () {
        $metrics = makeQueueContractsMetrics(pending: ['p1' => []]);

        expect($metrics->partitionLag())->toBe(0.0);
    });

    it('delegates the gauges only to the injected contracts', function () {
        $queue = new QueueContractsSizedQueue(2);
        $metrics = new ProcessingMetrics(
            new QueueContractsCountedPartitions(1),
            $queue,
        );

        expect($metrics->retryQueueSize())->toBe(2);
        expect($queue->retryQueueSizeCalls)->toBe(1);
    });
});

describe('ProcessingMetrics::snapshot() fiber-safety documentation (L5)', function () {
    it('documents the cooperative-scheduler assumption', function () {
        $doc = (new ReflectionMethod(ProcessingMetrics::class, 'snapshot'))->getDocComment();

        expect($doc)->not->toBeFalse();
        expect($doc)->toContain('Fiber-safety assumption');
        expect($doc)->toContain('cooperative scheduler');
        expect($doc)->toContain('preempted in the middle of a statement');
        expect($doc)->toContain('no locking');
        expect($doc)->toContain('torn or stale');
    });

    it('builds a snapshot without hitting a suspension point', function () {
        $metrics = makeQueueContractsMetrics(
            partitions: 2,
            retrySize: 1,
            pending: ['p1' => ['j1' => 'w1']],
            lengths: ['p1' => 4],
        );
        $metrics->recordExecution(40);

        $fiber = new Fiber(fn (): array => $metrics->snapshot());
        $fiber->start();

        expect($fiber->isTerminated())->toBeTrue();

        $snapshot = $fiber->getReturn();

        expect($snapshot)->toBeArray();
        expect($snapshot['jobsExecuted'])->toBe(1);
        expect($snapshot['avgExecutionTimeMs'])->toBe(40.0);
        expect($snapshot['activePartitions'])->toBe(2);
        expect($snapshot['retryQueueSize'])->toBe(1);
        expect($snapshot['pendingAckCount'])->toBe(1);
        expect(array_keys($snapshot))->toBe([
            'jobsExecuted',
            'jobsFailed',
            'jobsRetried',
            'jobsCompleted',
            'zombiesFound',
            'dedupHits',
            'deadLetterCount',
            'avgExecutionTimeMs',
            'executionTimeHistogram',
            'activePartitions',
            'retryQueueSize',
            'pendingAckCount',
            'partitionLag',
        ]);
    });

    it('returns derived values that match the dedicated accessors', function () {
        $metrics = makeQueueContractsMetrics(
            partitions: 4,
            retrySize: 3,
            pending: ['p1' => ['j1' => 'w1']],
        );
        $metrics->recordExecution(10);
        $metrics->recordExecution(30);

        $snapshot = $metrics->snapshot();

        expect($snapshot['avgExecutionTimeMs'])->toBe($metrics->averageExecutionTimeMs());
        expect($snapshot['executionTimeHistogram'])->toBe($metrics->executionTimeHistogram());
        expect($snapshot['activePartitions'])->toBe($metrics->activePartitionCount());
        expect($snapshot['retryQueueSize'])->toBe($metrics->retryQueueSize());
        expect($snapshot['pendingAckCount'])->toBe($metrics->pendingAckCount());
        expect($snapshot['partitionLag'])->toBe($metrics->partitionLag());
    });

    it('returns an array free of references to internal state', function () {
        $metrics = makeQueueContractsMetrics(retrySize: 1);
        $metrics->recordExecution(15);

        $snapshot = $metrics->snapshot();
        $snapshot['jobsExecuted'] = 999;
        $snapshot['executionTimeHistogram']['lt10ms'] = 999;
        $metrics->recordExecution(15);

        $fresh = $metrics->snapshot();

        expect($fresh['jobsExecuted'])->toBe(2);
        expect($fresh['executionTimeHistogram']['10_50ms'])->toBe(2);
    });
});
