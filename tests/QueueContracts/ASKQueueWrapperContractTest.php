<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\Contracts\Queue\ASKQueueAdapterContract;
use BAGArt\AsyncKernel\Wrappers\ASKQueueWrapper;

final class QueueContractsArrayAdapter implements ASKQueueAdapterContract
{
    /** @var array<string, list<string>> */
    private array $queues = [];

    /** @var list<string> */
    public array $pushed = [];

    /** @var list<string> */
    public array $popped = [];

    public function push(string $queueName, string $payload): void
    {
        $this->pushed[] = $queueName.'|'.$payload;
        $this->queues[$queueName][] = $payload;
    }

    public function pop(string $queueName): ?string
    {
        $this->popped[] = $queueName;

        if (($this->queues[$queueName] ?? []) === []) {
            return null;
        }

        return array_shift($this->queues[$queueName]);
    }

    public function size(string $queueName): int
    {
        return count($this->queues[$queueName] ?? []);
    }
}

describe('ASKQueueWrapper delegates to the kernel-local adapter contract (C1)', function () {
    it('is still marked deprecated', function () {
        $doc = (new ReflectionClass(ASKQueueWrapper::class))->getDocComment();

        expect($doc)->not->toBeFalse();
        expect($doc)->toContain('@deprecated');
    });

    it('delegates push, pop and len to the adapter', function () {
        $adapter = new QueueContractsArrayAdapter();
        $wrapper = new ASKQueueWrapper($adapter);

        $wrapper->push('emails', 'job-1');
        $wrapper->push('emails', 'job-2');

        expect($adapter->pushed)->toBe(['emails|job-1', 'emails|job-2']);
        expect($wrapper->len('emails'))->toBe(2);
        expect($wrapper->pop('emails'))->toBe('job-1');
        expect($wrapper->pop('emails'))->toBe('job-2');
        expect($wrapper->len('emails'))->toBe(0);
        expect($adapter->popped)->toBe(['emails', 'emails']);
    });

    it('returns null when popping from an empty queue', function () {
        $wrapper = new ASKQueueWrapper(new QueueContractsArrayAdapter());

        expect($wrapper->pop('empty'))->toBeNull();
        expect($wrapper->len('empty'))->toBe(0);
    });

    it('keeps queues isolated from each other', function () {
        $wrapper = new ASKQueueWrapper(new QueueContractsArrayAdapter());

        $wrapper->push('a', 'payload-a');
        $wrapper->push('b', 'payload-b');

        expect($wrapper->len('a'))->toBe(1);
        expect($wrapper->len('b'))->toBe(1);
        expect($wrapper->pop('a'))->toBe('payload-a');
        expect($wrapper->len('b'))->toBe(1);
    });

    it('rejects an object that does not implement the kernel contract', function () {
        $foreign = new class
        {
            public function push(string $queueName, string $payload): void
            {
            }

            public function pop(string $queueName): ?string
            {
                return null;
            }

            public function size(string $queueName): int
            {
                return 0;
            }
        };

        expect(fn () => new ASKQueueWrapper($foreign))->toThrow(TypeError::class);
    });
});
