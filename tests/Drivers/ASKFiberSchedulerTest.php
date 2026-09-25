<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\Drivers\ASKFiberScheduler;

describe('ASKFiberScheduler', function () {
    it('does not re-enqueue a terminated Fiber that threw', function () {
        $scheduler = new ASKFiberScheduler(batchSize: 10);

        $started = false;

        $scheduler->enqueue(function () use (&$started) {
            $started = true;
            throw new RuntimeException('fiber error');
        });

        // First tick starts the Fiber — it throws and becomes terminated.
        $scheduler->tick(0);

        expect($started)->toBeTrue();
        // Queue must be empty: the terminated Fiber was dropped, not re-enqueued.
        expect($scheduler->queueSize())->toBe(0);
        expect($scheduler->isIdle())->toBeTrue();
    });

    it('re-enqueues a suspended Fiber but drops a terminated one', function () {
        $scheduler = new ASKFiberScheduler(batchSize: 1);

        $resumed = false;

        $scheduler->enqueue(function () use (&$resumed) {
            Fiber::suspend();
            $resumed = true;
            throw new RuntimeException('second tick error');
        });

        // First tick: starts the Fiber, it suspends → re-enqueued.
        $scheduler->tick(0);
        expect($scheduler->queueSize())->toBe(1);
        expect($resumed)->toBeFalse();

        // Second tick: resumes the Fiber, it throws → terminated, not re-enqueued.
        $scheduler->tick(0);
        expect($resumed)->toBeTrue();
        expect($scheduler->queueSize())->toBe(0);
        expect($scheduler->isIdle())->toBeTrue();
    });

    it('continues processing remaining Fibers after one terminates', function () {
        $scheduler = new ASKFiberScheduler(batchSize: 10);

        $order = [];

        $scheduler->enqueue(function () use (&$order) {
            $order[] = 'a';
            throw new RuntimeException('fail');
        });

        $scheduler->enqueue(function () use (&$order) {
            $order[] = 'b';
        });

        $scheduler->tick(0);

        expect($order)->toBe(['a', 'b']);
        expect($scheduler->queueSize())->toBe(0);
    });
});
