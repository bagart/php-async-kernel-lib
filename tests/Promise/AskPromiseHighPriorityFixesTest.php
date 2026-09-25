<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\Promise\ASKPromise;
use BAGArt\AsyncKernel\Promise\ASKPromiseResolver;

describe('FIBER-RESUME-WITHOUT-CHECK', function () {
    it('skips non-suspended fiber without FiberError', function () {
        $resolver = new ASKPromiseResolver();

        $promise = ASKPromise::resolved('value');

        $fiber = new Fiber(function () use ($promise, $resolver) {
            $resolver->await($promise);
        });

        $fiber->start();

        $resolver->tick(0);

        expect($fiber->isStarted())->toBeTrue();
    });

    it('does not throw when fiber already completed before tick', function () {
        $resolver = new ASKPromiseResolver();

        $promise = ASKPromise::resolved('value');

        $fiber = new Fiber(function () use ($promise, $resolver) {
            return $resolver->await($promise);
        });

        $fiber->start();

        $resolver->tick(0);

        expect($fiber->isStarted())->toBeTrue();
    });
});

describe('PROMISE-FLUSH-DROPS-CALLBACKS', function () {
    it('invokes all callbacks even when one throws', function () {
        $invoked = [];

        $promise = ASKPromise::resolved('value');

        $promise->then(function () use (&$invoked) {
            $invoked[] = 'first';
            throw new RuntimeException('boom');
        });

        $promise->then(function () use (&$invoked) {
            $invoked[] = 'second';
        });

        $promise->then(function () use (&$invoked) {
            $invoked[] = 'third';
        });

        expect($invoked)->toBe(['first', 'second', 'third']);
    });

    it('keeps parent fulfilled while callback errors reject the children (Q4)', function () {
        $promise = ASKPromise::resolved('value');

        $childFirst = $promise->then(function () {
            throw new RuntimeException('first-error');
        });

        $childSecond = $promise->then(function () {
            throw new LogicException('second-error');
        });

        // Q4: a settled parent is immutable; the error rejects the .then() child only.
        expect($promise->getState())->toBe(ASKPromise::FULFILLED);
        expect($childFirst->getState())->toBe(ASKPromise::REJECTED);
        expect($childFirst->getReason()->getMessage())->toBe('first-error');
        expect($childSecond->getState())->toBe(ASKPromise::REJECTED);
        expect($childSecond->getReason()->getMessage())->toBe('second-error');
    });

    it('still invokes subsequent callbacks after a throw', function () {
        $invoked = [];

        $promise = ASKPromise::resolved(42);

        $promise->then(function ($v) use (&$invoked) {
            $invoked[] = $v;
            throw new RuntimeException('fail');
        });

        $promise->then(function ($v) use (&$invoked) {
            $invoked[] = $v + 1;
        });

        expect($invoked)->toBe([42, 43]);
    });
});

describe('PROMISE-RESULT-CONTRACT-VIOLATION', function () {
    it('returns value when fulfilled', function () {
        $promise = ASKPromise::resolved('hello');

        expect($promise->result())->toBe('hello');
    });

    it('throws when rejected', function () {
        $reason = new RuntimeException('rejected');
        $promise = ASKPromise::rejected($reason);

        $promise->result();
    })->throws(RuntimeException::class, 'rejected');

    it('throws when canceled', function () {
        $promise = new ASKPromise();
        $promise->cancel();

        $promise->result();
    })->throws(RuntimeException::class, 'Promise cancelled');

    it('returns null when pending', function () {
        $promise = new ASKPromise();

        expect($promise->result())->toBeNull();
    });
});

describe('PROMISE-RESOLVER-PROPAGATES-ERRORS', function () {
    it('wait() returns immediately when promise already resolved', function () {
        $resolver = new ASKPromiseResolver();
        $promise = ASKPromise::resolved('done');

        $fiber = new Fiber(function () use ($promise, $resolver) {
            $resolver->wait($promise);
        });

        $fiber->start();

        expect($fiber->isStarted())->toBeTrue();
    });

    it('propagates rejection thrown out of Fiber::suspend in wait() (Q9)', function () {
        $resolver = new ASKPromiseResolver();
        $promise = new ASKPromise();

        $fiber = new Fiber(function () use ($promise, $resolver) {
            $resolver->wait($promise);
        });

        $fiber->start();

        $promise->reject(new RuntimeException('rejection-reason'));

        // Q9: wait() logs and re-throws — tick() surfaces the rejection to the caller.
        expect(fn () => $resolver->tick(0))->toThrow(RuntimeException::class, 'rejection-reason');
        expect($fiber->isStarted())->toBeTrue();
    });
});
