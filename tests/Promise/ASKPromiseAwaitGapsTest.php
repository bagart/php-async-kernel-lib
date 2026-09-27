<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
use BAGArt\AsyncKernel\Exceptions\ASKTechnicalException;
use BAGArt\AsyncKernel\Promise\ASKPromise;

/**
 * Builds a promise backed by a one-shot tickable that settles it on the first
 * wait() pump — covers the synchronous (non-Fiber) path of ASKPromise::wait().
 */
function askPromisePumpFixture(callable $settle): ASKPromise
{
    $tickable = new class implements ASKTickableContract {
        public ?Closure $onTick = null;

        private bool $fired = false;

        public function tick(int $systemPressure): void
        {
            if ($this->fired || $this->onTick === null) {
                return;
            }

            $this->fired = true;
            ($this->onTick)();
        }

        public function pressure(): int
        {
            return 0;
        }

        public function isIdle(): bool
        {
            return $this->fired;
        }

        public function queueSize(): int
        {
            return $this->fired ? 0 : 1;
        }
    };

    $promise = new ASKPromise($tickable);
    $tickable->onTick = static fn () => $settle($promise);

    return $promise;
}

describe('ASKPromise::await() error propagation in Fiber (M2)', function () {
    it('throws the rejection reason into the suspended Fiber', function () {
        $promise = new ASKPromise();
        $caught = null;

        $fiber = new Fiber(function () use ($promise, &$caught) {
            try {
                $promise->await();
            } catch (RuntimeException $e) {
                $caught = $e;
            }
        });

        $fiber->start();
        expect($caught)->toBeNull();

        $promise->reject(new RuntimeException('rejected-while-suspended'));

        expect($caught)->toBeInstanceOf(RuntimeException::class);
        expect($caught->getMessage())->toBe('rejected-while-suspended');
    });

    it('throws the cancellation reason into the suspended Fiber', function () {
        $promise = new ASKPromise();
        $caught = null;

        $fiber = new Fiber(function () use ($promise, &$caught) {
            try {
                $promise->await();
            } catch (ASKTechnicalException $e) {
                $caught = $e;
            }
        });

        $fiber->start();
        $promise->cancel();

        expect($caught)->toBeInstanceOf(ASKTechnicalException::class);
        expect($caught->getMessage())->toBe('Promise cancelled');
    });

    it('await(unwrap: false) returns null instead of throwing when rejected mid-await', function () {
        $promise = new ASKPromise();
        $result = 'sentinel';

        $fiber = new Fiber(function () use ($promise, &$result) {
            $result = $promise->await(unwrap: false);
        });

        $fiber->start();
        $promise->reject(new RuntimeException('ignored'));

        expect($result)->toBeNull();
        expect($fiber->isTerminated())->toBeTrue();
    });

    it('await(unwrap: false) returns null instead of throwing when canceled mid-await', function () {
        $promise = new ASKPromise();
        $result = 'sentinel';

        $fiber = new Fiber(function () use ($promise, &$result) {
            $result = $promise->await(unwrap: false);
        });

        $fiber->start();
        $promise->cancel();

        expect($result)->toBeNull();
    });

    it('unwraps a nested promise resolved while the Fiber is suspended', function () {
        $promise = new ASKPromise();
        $result = null;

        $fiber = new Fiber(function () use ($promise, &$result) {
            $result = $promise->await();
        });

        $fiber->start();
        $promise->resolve(ASKPromise::resolved('nested-value'));

        expect($result)->toBe('nested-value');
    });

    it('propagates a nested rejection while the Fiber is suspended', function () {
        $promise = new ASKPromise();
        $caught = null;

        $fiber = new Fiber(function () use ($promise, &$caught) {
            try {
                $promise->await();
            } catch (RuntimeException $e) {
                $caught = $e;
            }
        });

        $fiber->start();
        $promise->resolve(ASKPromise::rejected(new RuntimeException('nested-failure')));

        expect($caught)->toBeInstanceOf(RuntimeException::class);
        expect($caught->getMessage())->toBe('nested-failure');
    });
});

describe('ASKPromise chaining gaps (M2)', function () {
    it('unwraps a promise returned from a then handler', function () {
        $child = ASKPromise::resolved(1)->then(
            static fn (mixed $value): ASKPromise => ASKPromise::resolved($value + 41),
        );

        expect($child->getState())->toBe(ASKPromise::FULFILLED);
        expect($child->getValue())->toBe(42);
    });

    it('rejects the child when a promise returned from a then handler rejects', function () {
        $reason = new RuntimeException('inner-rejection');

        $child = ASKPromise::resolved('ok')->then(
            static fn (): ASKPromise => ASKPromise::rejected($reason),
        );

        expect($child->getState())->toBe(ASKPromise::REJECTED);
        expect($child->getReason())->toBe($reason);
    });

    it('drains handlers registered from inside a fulfilled flush', function () {
        $promise = ASKPromise::resolved('value');
        $innerCalled = false;

        $promise->then(function () use ($promise, &$innerCalled): void {
            $promise->then(function () use (&$innerCalled): void {
                $innerCalled = true;
            });
        });

        expect($innerCalled)->toBeTrue();
        expect($promise->getState())->toBe(ASKPromise::FULFILLED);
    });

    it('recovers from a chain break via otherwise on the rejected child', function () {
        $recovery = ASKPromise::resolved(1)
            ->then(static fn (int $v): int => $v + 1)
            ->then(static function (): never {
                throw new RuntimeException('chain-break');
            })
            ->otherwise(static fn (Throwable $e): string => 'recovered: '.$e->getMessage());

        expect($recovery->getState())->toBe(ASKPromise::FULFILLED);
        expect($recovery->getValue())->toBe('recovered: chain-break');
    });

    it('keeps rejecting through a chain when no handler is attached', function () {
        $reason = new RuntimeException('carried');

        $child = ASKPromise::rejected($reason)
            ->then(static fn (mixed $v): mixed => $v)
            ->then(static fn (mixed $v): mixed => $v);

        expect($child->getState())->toBe(ASKPromise::REJECTED);
        expect($child->getReason())->toBe($reason);
    });
});

describe('ASKPromise::wait() synchronous pump (M2)', function () {
    it('returns the pumped value when the tickable resolves the promise', function () {
        $promise = askPromisePumpFixture(
            static fn (ASKPromise $p) => $p->resolve('pumped-value'),
        );

        expect($promise->wait())->toBe('pumped-value');
        expect($promise->isCompleted())->toBeTrue();
    });

    it('throws the rejection reason when the tickable rejects the promise', function () {
        $promise = askPromisePumpFixture(
            static fn (ASKPromise $p) => $p->reject(new RuntimeException('pump-failure')),
        );

        expect(fn () => $promise->wait())->toThrow(RuntimeException::class, 'pump-failure');
    });

    it('throws the cancellation reason when the tickable cancels the promise', function () {
        $promise = askPromisePumpFixture(
            static fn (ASKPromise $p) => $p->cancel(),
        );

        expect(fn () => $promise->wait())->toThrow(ASKTechnicalException::class, 'Promise cancelled');
    });
});

describe('ASKPromise::await resume-callback contract (M7)', function () {
    it('wakes the suspended Fiber from promise state, not from handler return values', function () {
        $promise = new ASKPromise();

        $promise->then(static fn (mixed $value): string => strtoupper((string) $value));

        $result = null;
        $fiber = new Fiber(function () use ($promise, &$result): void {
            $result = $promise->await();
        });

        $fiber->start();
        $promise->resolve('raw');

        expect($result)->toBe('raw');
        expect($fiber->isTerminated())->toBeTrue();
    });

    it('resumes the Fiber exactly once when several handlers are already attached', function () {
        $promise = new ASKPromise();

        $promise->then(static fn (mixed $value): mixed => $value);
        $promise->then(static fn (mixed $value): mixed => $value);

        $result = null;
        $fiber = new Fiber(function () use ($promise, &$result): void {
            $result = $promise->await();
        });

        $fiber->start();
        $promise->resolve('once');

        expect($result)->toBe('once');
        expect($fiber->isTerminated())->toBeTrue();
    });

    it('keeps the promise fulfilled after the awaiting Fiber wakes up', function () {
        $promise = new ASKPromise();

        $fiber = new Fiber(function () use ($promise): mixed {
            return $promise->await();
        });

        $fiber->start();
        $promise->resolve('state-owner');

        expect($promise->getState())->toBe(ASKPromise::FULFILLED);
        expect($fiber->getReturn())->toBe('state-owner');
    });
});
