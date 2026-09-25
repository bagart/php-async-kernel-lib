<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\Drivers\ASKFiberScheduler;
use BAGArt\AsyncKernel\Promise\ASKDeferred;
use BAGArt\AsyncKernel\Promise\ASKPromise;

describe('ASKPromise comprehensive coverage', function () {
    it('resolved promise returns value via getValue', function () {
        $promise = ASKPromise::resolved(42);
        expect($promise->getValue())->toBe(42);
    });

    it('getValue throws when promise is not fulfilled', function () {
        $promise = new ASKPromise();
        expect(fn () => $promise->getValue())
            ->toThrow(\BAGArt\AsyncKernel\Exceptions\ASKTechnicalException::class);
    });

    it('getReason returns null for fulfilled promise', function () {
        $promise = ASKPromise::resolved('ok');
        expect($promise->getReason())->toBeNull();
    });

    it('getReason returns exception for rejected promise', function () {
        $reason = new RuntimeException('fail');
        $promise = ASKPromise::rejected($reason);
        expect($promise->getReason())->toBe($reason);
    });

    it('cancel sets canceled state and reason', function () {
        $promise = new ASKPromise();
        $promise->cancel();

        expect($promise->getState())->toBe(ASKPromise::CANCELED);
        expect($promise->getReason())->toBeInstanceOf(RuntimeException::class);
        expect($promise->getReason()->getMessage())->toBe('Promise cancelled');
    });

    it('cancel on settled promise is no-op', function () {
        $promise = ASKPromise::resolved('ok');
        $promise->cancel();

        expect($promise->getState())->toBe(ASKPromise::FULFILLED);
    });

    it('then chains fulfill callbacks', function () {
        $promise = ASKPromise::resolved(10);
        $child = $promise->then(fn ($v) => $v + 5);

        expect($child->getValue())->toBe(15);
    });

    it('then chains reject callbacks', function () {
        $reason = new RuntimeException('orig');
        $promise = ASKPromise::rejected($reason);

        $child = $promise->then(null, fn ($r) => throw new LogicException('wrapped'));

        expect($child->getState())->toBe(ASKPromise::REJECTED);
        expect($child->getReason())->toBeInstanceOf(LogicException::class);
    });

    it('then with no handlers passes through', function () {
        $promise = ASKPromise::resolved('val');
        $child = $promise->then();

        expect($child->getValue())->toBe('val');
    });

    it('otherwise handles rejection', function () {
        $reason = new RuntimeException('err');
        $promise = ASKPromise::rejected($reason);
        $handled = null;

        $promise->otherwise(function ($r) use (&$handled) {
            $handled = $r->getMessage();
        });

        expect($handled)->toBe('err');
    });

    it('otherwise on fulfilled promise is not called', function () {
        $promise = ASKPromise::resolved('ok');
        $called = false;

        $promise->otherwise(function () use (&$called) {
            $called = true;
        });

        expect($called)->toBeFalse();
    });

    it('onCompleted fires on resolve', function () {
        $promise = new ASKPromise();
        $called = false;

        $promise->onCompleted(function () use (&$called) {
            $called = true;
        });

        expect($called)->toBeFalse();
        $promise->resolve('x');
        expect($called)->toBeTrue();
    });

    it('onCompleted fires on reject', function () {
        $promise = new ASKPromise();
        $called = false;

        $promise->onCompleted(function () use (&$called) {
            $called = true;
        });

        expect($called)->toBeFalse();
        $promise->reject(new RuntimeException('e'));
        expect($called)->toBeTrue();
    });

    it('deep chain propagates values', function () {
        $promise = ASKPromise::resolved(1);

        $child = $promise
            ->then(fn ($v) => $v + 1)
            ->then(fn ($v) => $v * 3)
            ->then(fn ($v) => "result-{$v}");

        expect($child->getValue())->toBe('result-6');
    });

    it('chain breaks on handler error', function () {
        $promise = ASKPromise::resolved(1);

        $child = $promise
            ->then(fn ($v) => $v + 1)
            ->then(function () {
                throw new RuntimeException('chain-break');
            })
            ->then(fn ($v) => $v + 100);

        expect($child->getState())->toBe(ASKPromise::REJECTED);
        expect($child->getReason()->getMessage())->toBe('chain-break');
    });

    it('resolve with nested promise chains correctly', function () {
        $inner = ASKPromise::resolved(99);
        $outer = new ASKPromise();
        $result = null;

        $outer->then(function ($v) use (&$result) {
            $result = $v;
        });

        $outer->resolve($inner);

        expect($result)->toBe(99);
    });

    it('concurrent resolve calls are idempotent', function () {
        $promise = new ASKPromise();
        $callCount = 0;

        $promise->then(function () use (&$callCount) {
            $callCount++;
        });

        $promise->resolve('first');
        $promise->resolve('second');

        expect($promise->getValue())->toBe('first');
        expect($callCount)->toBe(1);
    });

    it('concurrent reject calls are idempotent', function () {
        $promise = new ASKPromise();
        $callCount = 0;

        $promise->then(null, function () use (&$callCount) {
            $callCount++;
        });

        $promise->reject(new RuntimeException('first'));
        $promise->reject(new RuntimeException('second'));

        expect($promise->getReason()->getMessage())->toBe('first');
        expect($callCount)->toBe(1);
    });

    it('result() throws for rejected promise', function () {
        $reason = new RuntimeException('rejected');
        $promise = ASKPromise::rejected($reason);

        expect(fn () => $promise->result())->toThrow(RuntimeException::class, 'rejected');
    });

    it('result() throws for canceled promise', function () {
        $promise = new ASKPromise();
        $promise->cancel();

        expect(fn () => $promise->result())->toThrow(RuntimeException::class, 'Promise cancelled');
    });

    it('result() returns null for pending promise', function () {
        $promise = new ASKPromise();
        expect($promise->result())->toBeNull();
    });

    it('isCompleted returns true for all settled states', function () {
        expect(ASKPromise::resolved('v')->isCompleted())->toBeTrue();
        expect(ASKPromise::rejected(new RuntimeException())->isCompleted())->toBeTrue();

        $canceled = new ASKPromise();
        $canceled->cancel();
        expect($canceled->isCompleted())->toBeTrue();
    });

    it('isCompleted returns false for pending', function () {
        $promise = new ASKPromise();
        expect($promise->isCompleted())->toBeFalse();
    });

    it('error() returns null for fulfilled promise', function () {
        $promise = ASKPromise::resolved('ok');
        expect($promise->error())->toBeNull();
    });

    it('error() returns reason for rejected promise', function () {
        $reason = new RuntimeException('err');
        $promise = ASKPromise::rejected($reason);
        expect($promise->error())->toBe($reason);
    });

    it('error() returns reason for canceled promise', function () {
        $promise = new ASKPromise();
        $promise->cancel();
        expect($promise->error())->toBeInstanceOf(RuntimeException::class);
    });
});

describe('ASKPromise await in Fiber', function () {
    it('await returns resolved value', function () {
        $promise = ASKPromise::resolved('hello');
        $result = null;

        $fiber = new Fiber(function () use ($promise, &$result) {
            $result = $promise->await();
        });

        $fiber->start();
        expect($result)->toBe('hello');
    });

    it('await throws on rejected promise', function () {
        $promise = ASKPromise::rejected(new RuntimeException('err'));
        $caught = null;

        $fiber = new Fiber(function () use ($promise, &$caught) {
            try {
                $promise->await();
            } catch (RuntimeException $e) {
                $caught = $e;
            }
        });

        $fiber->start();
        expect($caught)->toBeInstanceOf(RuntimeException::class);
        expect($caught->getMessage())->toBe('err');
    });

    it('await suspends and resumes on pending promise', function () {
        $promise = new ASKPromise();
        $result = null;

        $fiber = new Fiber(function () use ($promise, &$result) {
            $result = $promise->await();
        });

        $fiber->start();
        expect($result)->toBeNull();
        expect($fiber->isSuspended())->toBeTrue();

        $promise->resolve('resolved');
        expect($result)->toBe('resolved');
    });

    it('await with unwrap=false returns value for rejected promise', function () {
        $reason = new RuntimeException('err');
        $promise = ASKPromise::rejected($reason);
        $result = null;

        $fiber = new Fiber(function () use ($promise, &$result) {
            $result = $promise->await(unwrap: false);
        });

        $fiber->start();
        expect($result)->toBeNull();
    });

    it('await returns immediately for resolved promise outside Fiber', function () {
        $promise = ASKPromise::resolved('ok');

        // Resolved promise returns value directly without needing Fiber context.
        expect($promise->await())->toBe('ok');
    });
});

describe('ASKPromise wait() without Fiber', function () {
    it('wait throws when no tickables provided', function () {
        $promise = ASKPromise::resolved('done');

        // wait() requires tickables even for already-resolved promises.
        expect(fn () => $promise->wait())->toThrow(
            \BAGArt\AsyncKernel\Exceptions\ASKTechnicalException::class
        );
    });

    it('wait throws for rejected promise when no tickables provided', function () {
        $promise = ASKPromise::rejected(new RuntimeException('fail'));

        expect(fn () => $promise->wait())->toThrow(
            \BAGArt\AsyncKernel\Exceptions\ASKTechnicalException::class
        );
    });

    it('wait with tickable resolves pending promise', function () {
        $scheduler = new ASKFiberScheduler();
        $promise = new ASKPromise($scheduler);

        $fiber = new Fiber(function () use ($promise) {
            return $promise->await();
        });

        $scheduler->enqueue($fiber);
        $scheduler->tick(0);

        // Promise still pending — resolve it
        $promise->resolve('result');
        $scheduler->tick(0);

        expect($fiber->getReturn())->toBe('result');
    });

    it('wait without tickables throws for pending promise', function () {
        $promise = new ASKPromise();

        expect(fn () => $promise->wait())->toThrow(
            \BAGArt\AsyncKernel\Exceptions\ASKTechnicalException::class
        );
    });
});

describe('ASKDeferred coverage', function () {
    it('deferred resolves via resolve method', function () {
        $deferred = new ASKDeferred();
        expect($deferred->isCompleted())->toBeFalse();

        $deferred->resolve('value');

        expect($deferred->isCompleted())->toBeTrue();
        expect($deferred->result())->toBe('value');
    });

    it('deferred rejects via reject method', function () {
        $deferred = new ASKDeferred();
        $reason = new RuntimeException('err');

        $deferred->reject($reason);

        expect($deferred->isCompleted())->toBeTrue();
        expect($deferred->error())->toBe($reason);
    });

    it('deferred promise returns internal promise', function () {
        $deferred = new ASKDeferred();
        expect($deferred->promise())->toBeInstanceOf(ASKPromise::class);
    });

    it('deferred onCompleted fires on resolve', function () {
        $deferred = new ASKDeferred();
        $called = false;

        $deferred->onCompleted(function () use (&$called) {
            $called = true;
        });

        expect($called)->toBeFalse();
        $deferred->resolve('x');
        expect($called)->toBeTrue();
    });

    it('deferred await returns resolved value', function () {
        $deferred = new ASKDeferred();
        $result = null;

        $fiber = new Fiber(function () use ($deferred, &$result) {
            $result = $deferred->await();
        });

        $fiber->start();
        expect($result)->toBeNull();

        $deferred->resolve('done');
        expect($result)->toBe('done');
    });
});
