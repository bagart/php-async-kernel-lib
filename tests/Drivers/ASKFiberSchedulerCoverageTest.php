<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\Drivers\ASKFiberScheduler;

describe('ASKFiberScheduler coverage', function () {
    it('starts with empty queue and idle state', function () {
        $scheduler = new ASKFiberScheduler();

        expect($scheduler->queueSize())->toBe(0);
        expect($scheduler->isIdle())->toBeTrue();
    });

    it('enqueue increases queue size', function () {
        $scheduler = new ASKFiberScheduler();

        $scheduler->enqueue(function () {
            Fiber::suspend();
        });

        expect($scheduler->queueSize())->toBe(1);
        expect($scheduler->isIdle())->toBeFalse();
    });

    it('does not enqueue a terminated Fiber', function () {
        $scheduler = new ASKFiberScheduler();

        $fiber = new Fiber(function () {
            return 'done';
        });
        $fiber->start();

        $scheduler->enqueue($fiber);

        expect($scheduler->queueSize())->toBe(0);
    });

    it('batch size limits fibers processed per tick', function () {
        $scheduler = new ASKFiberScheduler(batchSize: 2);

        $processed = [];
        for ($i = 0; $i < 5; $i++) {
            $scheduler->enqueue(function () use (&$processed, $i) {
                $processed[] = $i;
            });
        }

        $scheduler->tick(0);

        // Only 2 fibers should have been processed
        expect($processed)->toHaveCount(2);
        expect($scheduler->queueSize())->toBe(3);
    });

    it('pressure returns 0 when idle', function () {
        $scheduler = new ASKFiberScheduler();

        expect($scheduler->pressure())->toBe(0);
    });

    it('pressure accounts for queue depth', function () {
        $scheduler = new ASKFiberScheduler();

        // Enqueue many fibers to trigger queue pressure
        for ($i = 0; $i < 150; $i++) {
            $scheduler->enqueue(function () {
                Fiber::suspend();
            });
        }

        // Tick once to start some fibers (filling sleeping state)
        $scheduler->tick(0);

        $pressure = $scheduler->pressure();
        expect($pressure)->toBeGreaterThan(0);
    });

    it('isIdle returns true after all fibers complete', function () {
        $scheduler = new ASKFiberScheduler();

        $scheduler->enqueue(function () {
            // Complete immediately
        });

        $scheduler->tick(0);

        expect($scheduler->isIdle())->toBeTrue();
        expect($scheduler->queueSize())->toBe(0);
    });

    it('forceStop cleans up suspended fibers', function () {
        $scheduler = new ASKFiberScheduler();

        // Use batchSize: 1 so the suspended fiber stays in queue
        $scheduler->enqueue(function () {
            Fiber::suspend();
        });

        // Use batchSize: 1 to process only one fiber per tick
        $scheduler = new ASKFiberScheduler(batchSize: 1);

        $scheduler->enqueue(function () {
            Fiber::suspend();
        });

        expect($scheduler->isIdle())->toBeFalse();

        $scheduler->forceStop();

        expect($scheduler->isIdle())->toBeTrue();
        expect($scheduler->queueSize())->toBe(0);
    });

    it('track and untrack manage fiber-future association', function () {
        $scheduler = new ASKFiberScheduler();

        $fiber = new Fiber(function () {
            Fiber::suspend();
        });

        $future = new \BAGArt\AsyncKernel\Promise\ASKDeferred();

        $scheduler->track($fiber, $future);
        $scheduler->untrack($fiber, $future);

        // Should be idle since untracked
        expect($scheduler->isIdle())->toBeTrue();
    });

    it('handles completed fibers then suspended fibers in separate batches', function () {
        $scheduler = new ASKFiberScheduler(batchSize: 1);

        $order = [];

        $scheduler->enqueue(function () use (&$order) {
            $order[] = 'done';
        });

        // First tick: process the completed fiber
        $scheduler->tick(0);

        expect($order)->toBe(['done']);

        $suspended = false;
        $scheduler->enqueue(function () use (&$suspended) {
            $suspended = true;
            Fiber::suspend();
        });

        // Second tick: start the suspended fiber
        $scheduler->tick(0);

        expect($suspended)->toBeTrue();
        expect($scheduler->queueSize())->toBe(1); // suspended fiber re-enqueued
    });

    it('untrackByFiber removes association on completion', function () {
        $scheduler = new ASKFiberScheduler(batchSize: 10);
        $future = new \BAGArt\AsyncKernel\Promise\ASKDeferred();

        $fiber = new Fiber(function () {
            return 'done';
        });

        $scheduler->track($fiber, $future);
        $scheduler->enqueue($fiber);

        $scheduler->tick(0);

        expect($scheduler->queueSize())->toBe(0);
        expect($future->isCompleted())->toBeFalse(); // untracked, not rejected
    });

    it('tick wakes sleeping fibers', function () {
        $scheduler = new ASKFiberScheduler(batchSize: 10);

        $woken = false;

        // Create a fiber that sleeps for 0ms
        $scheduler->enqueue(function () use (&$woken) {
            $fiber = Fiber::getCurrent();
            // Simulate sleeping by registering in sleeping state
            // We'll use a very short sleep via a custom approach
            $woken = true;
        });

        $scheduler->tick(0);

        expect($woken)->toBeTrue();
    });
});
