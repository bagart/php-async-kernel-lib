<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\Promise\ASKPromise;
use BAGArt\AsyncKernel\Promise\ASKPromiseResolver;

describe('ASKPromise coverage', function () {
    it('resolved promise has fulfilled state', function () {
        $promise = ASKPromise::resolved('hello');

        expect($promise->getState())->toBe(ASKPromise::FULFILLED);
        expect($promise->getValue())->toBe('hello');
    });

    it('rejected promise has rejected state', function () {
        $reason = new RuntimeException('fail');
        $promise = ASKPromise::rejected($reason);

        expect($promise->getState())->toBe(ASKPromise::REJECTED);
        expect($promise->getReason())->toBe($reason);
    });

    it('pending promise starts in pending state', function () {
        $promise = new ASKPromise();

        expect($promise->getState())->toBe(ASKPromise::PENDING);
        expect($promise->getReason())->toBeNull();
    });

    it('resolve sets value and triggers callbacks', function () {
        $promise = new ASKPromise();
        $resolvedValue = null;

        $promise->then(function ($v) use (&$resolvedValue) {
            $resolvedValue = $v;
        });

        $promise->resolve(42);

        expect($resolvedValue)->toBe(42);
        expect($promise->getState())->toBe(ASKPromise::FULFILLED);
    });

    it('reject sets reason and triggers callbacks', function () {
        $promise = new ASKPromise();
        $reason = new RuntimeException('error');
        $caughtReason = null;

        $promise->then(null, function ($r) use (&$caughtReason) {
            $caughtReason = $r;
        });

        $promise->reject($reason);

        expect($caughtReason)->toBe($reason);
        expect($promise->getState())->toBe(ASKPromise::REJECTED);
    });

    it('cancel sets canceled state', function () {
        $promise = new ASKPromise();
        $promise->cancel();

        expect($promise->getState())->toBe(ASKPromise::CANCELED);
        expect($promise->getReason())->toBeInstanceOf(RuntimeException::class);
    });

    it('resolve on settled promise is no-op', function () {
        $promise = ASKPromise::resolved('first');

        $promise->resolve('second');

        expect($promise->getValue())->toBe('first');
    });

    it('reject on settled promise is no-op', function () {
        $promise = ASKPromise::resolved('first');
        $reason = new RuntimeException('error');

        $promise->reject($reason);

        expect($promise->getState())->toBe(ASKPromise::FULFILLED);
        expect($promise->getReason())->toBeNull();
    });

    it('then chains values through transforms', function () {
        $promise = ASKPromise::resolved(10);

        $child = $promise->then(fn ($v) => $v * 2);

        expect($child)->toBeInstanceOf(ASKPromise::class);
        expect($child->getValue())->toBe(20);
    });

    it('then catch propagates rejection', function () {
        $reason = new RuntimeException('original');
        $promise = ASKPromise::rejected($reason);

        $child = $promise->then(null, fn ($r) => throw $r);

        expect($child->getState())->toBe(ASKPromise::REJECTED);
        expect($child->getReason())->toBe($reason);
    });

    it('otherwise is shorthand for then(null, onRejected)', function () {
        $reason = new RuntimeException('fail');
        $promise = ASKPromise::rejected($reason);
        $caught = null;

        $promise->otherwise(function ($r) use (&$caught) {
            $caught = $r;
        });

        expect($caught)->toBe($reason);
    });

    it('onCompleted fires for fulfilled promise', function () {
        $promise = ASKPromise::resolved('ok');
        $called = false;

        $promise->onCompleted(function () use (&$called) {
            $called = true;
        });

        expect($called)->toBeTrue();
    });

    it('onCompleted fires for rejected promise', function () {
        $promise = ASKPromise::rejected(new RuntimeException('fail'));
        $called = false;

        $promise->onCompleted(function () use (&$called) {
            $called = true;
        });

        expect($called)->toBeTrue();
    });

    it('onCompleted fires for pending promise when resolved', function () {
        $promise = new ASKPromise();
        $called = false;

        $promise->onCompleted(function () use (&$called) {
            $called = true;
        });

        expect($called)->toBeFalse();

        $promise->resolve('ok');

        expect($called)->toBeTrue();
    });

    it('result() returns value when fulfilled', function () {
        $promise = ASKPromise::resolved('hello');

        expect($promise->result())->toBe('hello');
    });

    it('result() returns null when pending', function () {
        $promise = new ASKPromise();

        expect($promise->result())->toBeNull();
    });

    it('error() returns reason when rejected', function () {
        $reason = new RuntimeException('fail');
        $promise = ASKPromise::rejected($reason);

        expect($promise->error())->toBe($reason);
    });

    it('error() returns null when fulfilled', function () {
        $promise = ASKPromise::resolved('ok');

        expect($promise->error())->toBeNull();
    });

    it('isCompleted returns false for pending promise', function () {
        $promise = new ASKPromise();

        expect($promise->isCompleted())->toBeFalse();
    });

    it('isCompleted returns true for settled promise', function () {
        expect(ASKPromise::resolved('ok')->isCompleted())->toBeTrue();
        expect(ASKPromise::rejected(new RuntimeException())->isCompleted())->toBeTrue();
    });

    it('resolve with another promise chains values', function () {
        $inner = ASKPromise::resolved(42);
        $outer = new ASKPromise();
        $result = null;

        $outer->then(function ($v) use (&$result) {
            $result = $v;
        });

        $outer->resolve($inner);

        expect($result)->toBe(42);
    });
});

describe('ASKPromiseResolver coverage', function () {
    it('isReady returns true inside Fiber', function () {
        $resolver = new ASKPromiseResolver();
        $result = null;

        $fiber = new Fiber(function () use ($resolver, &$result) {
            $result = $resolver->isReady();
        });

        $fiber->start();

        expect($result)->toBeTrue();
    });

    it('isReady returns false outside Fiber', function () {
        $resolver = new ASKPromiseResolver();

        expect($resolver->isReady())->toBeFalse();
    });

    it('await returns value for resolved promise', function () {
        $resolver = new ASKPromiseResolver();
        $promise = ASKPromise::resolved('done');
        $result = null;

        $fiber = new Fiber(function () use ($promise, $resolver, &$result) {
            $result = $resolver->await($promise);
        });

        $fiber->start();

        expect($result)->toBe('done');
    });

    it('await throws for rejected promise', function () {
        $resolver = new ASKPromiseResolver();
        $promise = ASKPromise::rejected(new RuntimeException('fail'));
        $caught = null;

        $fiber = new Fiber(function () use ($promise, $resolver, &$caught) {
            try {
                $resolver->await($promise);
            } catch (RuntimeException $e) {
                $caught = $e;
            }
        });

        $fiber->start();

        expect($caught)->toBeInstanceOf(RuntimeException::class);
        expect($caught->getMessage())->toBe('fail');
    });

    it('queueSize tracks pending fibers', function () {
        $resolver = new ASKPromiseResolver();
        $promise = new ASKPromise();

        $fiber = new Fiber(function () use ($promise, $resolver) {
            $resolver->await($promise);
        });

        $fiber->start();

        expect($resolver->queueSize())->toBe(1);
        expect($resolver->isIdle())->toBeFalse();
    });

    it('tick resumes fulfilled fibers', function () {
        $resolver = new ASKPromiseResolver();
        $promise = ASKPromise::resolved('value');
        $result = null;

        $fiber = new Fiber(function () use ($promise, $resolver, &$result) {
            $result = $resolver->await($promise);
        });

        $fiber->start();
        $resolver->tick(0);

        expect($result)->toBe('value');
        expect($resolver->isIdle())->toBeTrue();
    });

    it('tick throws into rejected fibers', function () {
        $resolver = new ASKPromiseResolver();
        $promise = new ASKPromise();
        $caught = null;

        $fiber = new Fiber(function () use ($promise, $resolver, &$caught) {
            try {
                $resolver->await($promise);
            } catch (RuntimeException $e) {
                $caught = $e;
            }
        });

        $fiber->start();

        $promise->reject(new RuntimeException('error'));
        $resolver->tick(0);

        expect($caught)->toBeInstanceOf(RuntimeException::class);
        expect($caught->getMessage())->toBe('error');
    });

    it('pressure always returns 0', function () {
        $resolver = new ASKPromiseResolver();

        expect($resolver->pressure())->toBe(0);
    });

    it('wait throws outside Fiber', function () {
        $resolver = new ASKPromiseResolver();
        $promise = new ASKPromise();

        expect(fn () => $resolver->wait($promise))
            ->toThrow(\BAGArt\AsyncKernel\Exceptions\ASKTechnicalException::class);
    });
});
