<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\Exceptions\ASKException;
use BAGArt\AsyncKernel\Exceptions\ASKTechnicalException;
use BAGArt\AsyncKernel\Promise\ASKPromise;
use BAGArt\AsyncKernel\Promise\ASKPromiseResolver;

describe('ASKPromiseResolver::await() coverage (M2)', function () {
    it('throws when awaiting a pending promise outside a Fiber', function () {
        $resolver = new ASKPromiseResolver();
        $promise = new ASKPromise();

        expect(fn () => $resolver->await($promise))->toThrow(
            ASKTechnicalException::class,
            '[PromiseResolver::await] await() must be called inside Fiber',
        );
        expect($resolver->isIdle())->toBeTrue();
    });

    it('returns the settled value when awaiting an already fulfilled promise outside a Fiber', function () {
        $resolver = new ASKPromiseResolver();

        expect($resolver->await(ASKPromise::resolved('settled')))->toBe('settled');
    });

    it('throws when awaiting an already rejected promise outside a Fiber', function () {
        $resolver = new ASKPromiseResolver();
        $promise = ASKPromise::rejected(new RuntimeException('already-rejected'));

        expect(fn () => $resolver->await($promise))->toThrow(RuntimeException::class, 'already-rejected');
    });

    it('times out a suspended Fiber when the deadline passes without settlement', function () {
        $resolver = new ASKPromiseResolver();
        $promise = new ASKPromise();
        $caught = null;

        $fiber = new Fiber(function () use ($resolver, $promise, &$caught) {
            try {
                // timeout is expressed in seconds (deadline = now + $timeout).
                $resolver->await($promise, timeout: 1);
            } catch (ASKException $e) {
                $caught = $e;
            }
        });

        $fiber->start();
        expect($resolver->queueSize())->toBe(1);

        $resolver->tick(0);
        expect($caught)->toBeNull();
        expect($resolver->queueSize())->toBe(1);

        $limit = microtime(true) + 3.0;

        while ($caught === null && microtime(true) < $limit) {
            usleep(50_000);
            $resolver->tick(0);
        }

        expect($caught)->toBeInstanceOf(ASKException::class);
        expect($caught->getMessage())->toBe('[PromiseResolver::await] Promise timeout');
        expect($resolver->isIdle())->toBeTrue();
        expect($resolver->queueSize())->toBe(0);
    });

    it('does not time out when the promise settles before the deadline', function () {
        $resolver = new ASKPromiseResolver();
        $promise = new ASKPromise();
        $result = null;

        $fiber = new Fiber(function () use ($resolver, $promise, &$result) {
            $result = $resolver->await($promise, timeout: 5);
        });

        $fiber->start();
        $promise->resolve('in-time');
        $resolver->tick(0);

        expect($result)->toBe('in-time');
        expect($resolver->isIdle())->toBeTrue();
    });

    it('throws the cancellation reason into a Fiber awaiting a canceled promise', function () {
        $resolver = new ASKPromiseResolver();
        $promise = new ASKPromise();
        $caught = null;

        $fiber = new Fiber(function () use ($resolver, $promise, &$caught) {
            try {
                $resolver->await($promise);
            } catch (ASKTechnicalException $e) {
                $caught = $e;
            }
        });

        $fiber->start();
        $promise->cancel();
        $resolver->tick(0);

        expect($caught)->toBeInstanceOf(ASKTechnicalException::class);
        expect($caught->getMessage())->toBe('Promise cancelled');
        expect($resolver->isIdle())->toBeTrue();
    });

    it('surfaces the cancellation when the awaiting Fiber does not catch it', function () {
        $resolver = new ASKPromiseResolver();
        $promise = new ASKPromise();

        $fiber = new Fiber(static function () use ($resolver, $promise): void {
            $resolver->await($promise);
        });

        $fiber->start();
        $promise->cancel();

        expect(fn () => $resolver->tick(0))->toThrow(
            ASKTechnicalException::class,
            'Promise cancelled',
        );
        expect($resolver->isIdle())->toBeTrue();
    });

    it('resumes a Fiber blocked in wait() and returns void', function () {
        $resolver = new ASKPromiseResolver();
        $promise = new ASKPromise();
        $completed = false;

        $fiber = new Fiber(function () use ($resolver, $promise, &$completed) {
            $resolver->wait($promise);
            $completed = true;
        });

        $fiber->start();
        expect($completed)->toBeFalse();
        expect($resolver->queueSize())->toBe(1);

        $promise->resolve('done');
        $resolver->tick(0);

        expect($completed)->toBeTrue();
        expect($fiber->isTerminated())->toBeTrue();
        expect($resolver->isIdle())->toBeTrue();
    });

    it('keeps the queue until tick() even after the promise settles', function () {
        $resolver = new ASKPromiseResolver();
        $promise = new ASKPromise();

        $fiber = new Fiber(static function () use ($resolver, $promise): mixed {
            return $resolver->await($promise);
        });

        $fiber->start();
        $promise->resolve('late');
        expect($resolver->queueSize())->toBe(1);

        $resolver->tick(0);

        expect($fiber->getReturn())->toBe('late');
        expect($resolver->queueSize())->toBe(0);
        expect($resolver->isIdle())->toBeTrue();
    });
});
