<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\Exceptions\ASKAggregateException;
use BAGArt\AsyncKernel\Promise\ASKPromise;
use BAGArt\AsyncKernel\Promise\ASKPromiseResolver;

describe('ASKPromiseResolver multiple fiber failures (C5)', function () {
    it('retains all exceptions when multiple Fibers fail in one tick', function () {
        $resolver = new ASKPromiseResolver();

        // await() short-circuits settled promises (unwrap throws inside the
        // Fiber at start()), so C5 aggregation must be exercised with fibers
        // suspended on pending promises that settle before tick().
        $promiseA = new ASKPromise();
        $promiseB = new ASKPromise();

        $fiberA = new Fiber(function () use ($promiseA, $resolver) {
            $resolver->await($promiseA);
        });

        $fiberB = new Fiber(function () use ($promiseB, $resolver) {
            $resolver->await($promiseB);
        });

        $fiberA->start();
        $fiberB->start();

        $promiseA->reject(new RuntimeException('failure-A'));
        $promiseB->reject(new RuntimeException('failure-B'));

        try {
            $resolver->tick(0);
            $this->fail('Expected ASKAggregateException to be thrown');
        } catch (ASKAggregateException $e) {
            $exceptions = $e->getExceptions();
            expect($exceptions)->toHaveCount(2);

            $messages = array_map(
                static fn (\Throwable $ex) => $ex->getMessage(),
                $exceptions,
            );
            expect($messages)->toContain('failure-A');
            expect($messages)->toContain('failure-B');
        }
    });

    it('throws single exception when only one Fiber fails', function () {
        $resolver = new ASKPromiseResolver();

        $promise = new ASKPromise();

        $fiber = new Fiber(function () use ($promise, $resolver) {
            $resolver->await($promise);
        });

        $fiber->start();

        $promise->reject(new RuntimeException('single-failure'));

        try {
            $resolver->tick(0);
            $this->fail('Expected exception to be thrown');
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toBe('single-failure');
        }
    });

    it('returns normally when no Fibers are pending', function () {
        $resolver = new ASKPromiseResolver();

        // tick() on empty resolver should not throw.
        $resolver->tick(0);

        expect(true)->toBeTrue();
    });

    it('successful Fibers continue processing alongside failures', function () {
        $resolver = new ASKPromiseResolver();

        $promiseSuccess = new ASKPromise();
        $promiseFail = new ASKPromise();

        $successResult = null;

        $fiberSuccess = new Fiber(function () use ($promiseSuccess, $resolver, &$successResult) {
            $successResult = $resolver->await($promiseSuccess);
        });

        $fiberFail = new Fiber(function () use ($promiseFail, $resolver) {
            $resolver->await($promiseFail);
        });

        $fiberSuccess->start();
        $fiberFail->start();

        $promiseSuccess->resolve('ok');
        $promiseFail->reject(new RuntimeException('fail'));

        try {
            $resolver->tick(0);
        } catch (\Throwable) {
            // Failure surfaced after the successful Fiber was resumed.
        }

        expect($successResult)->toBe('ok');
    });

    it('exception ordering is deterministic (first failure first)', function () {
        $resolver = new ASKPromiseResolver();

        $promises = [];
        for ($i = 0; $i < 5; $i++) {
            $promise = new ASKPromise();
            $promises[$i] = $promise;

            $fiber = new Fiber(function () use ($promise, $resolver) {
                $resolver->await($promise);
            });
            $fiber->start();
        }

        for ($i = 0; $i < 5; $i++) {
            $promises[$i]->reject(new RuntimeException("error-{$i}"));
        }

        try {
            $resolver->tick(0);
            $this->fail('Expected ASKAggregateException');
        } catch (ASKAggregateException $e) {
            $caught = $e->getExceptions();
            expect($caught)->toHaveCount(5);

            for ($i = 0; $i < 5; $i++) {
                expect($caught[$i]->getMessage())->toBe("error-{$i}");
            }
        }
    });
});
