<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\Drivers\ASKFiberScheduler;
use BAGArt\AsyncKernel\Promise\ASKDeferred;

describe('ASKFiberScheduler resume error handling', function () {
    it('rejects associated Future when fiber throws on resume', function () {
        $scheduler = new ASKFiberScheduler(batchSize: 1);
        $future = new ASKDeferred();

        $fiber = new Fiber(function () {
            Fiber::suspend();
            throw new RuntimeException('resume-failure');
        });

        $scheduler->track($fiber, $future);
        $scheduler->enqueue($fiber);

        $scheduler->tick(0);
        expect($scheduler->queueSize())->toBe(1);
        expect($future->isCompleted())->toBeFalse();

        $scheduler->tick(0);
        expect($future->isCompleted())->toBeTrue()
            ->and($future->error())->toBeInstanceOf(RuntimeException::class)
            ->and($future->error()->getMessage())->toBe('resume-failure');
    });

    it('does not re-enqueue a fiber that threw during resume', function () {
        $scheduler = new ASKFiberScheduler(batchSize: 1);
        $future = new ASKDeferred();

        $fiber = new Fiber(function () {
            Fiber::suspend();
            throw new RuntimeException('boom');
        });

        $scheduler->track($fiber, $future);
        $scheduler->enqueue($fiber);

        $scheduler->tick(0);
        $scheduler->tick(0);

        expect($scheduler->queueSize())->toBe(0)
            ->and($scheduler->isIdle())->toBeTrue();
    });

    it('untracks fiber on normal completion', function () {
        $scheduler = new ASKFiberScheduler(batchSize: 10);

        $fiber = new Fiber(function () {
            return 'done';
        });

        $future = new ASKDeferred();
        $scheduler->track($fiber, $future);
        $scheduler->enqueue($fiber);

        $scheduler->tick(0);

        expect($scheduler->queueSize())->toBe(0)
            ->and($future->isCompleted())->toBeFalse();
    });

    it('continues processing other fibers after one throws on resume', function () {
        $scheduler = new ASKFiberScheduler(batchSize: 10);
        $future1 = new ASKDeferred();
        $future2 = new ASKDeferred();
        $order = [];

        $fiber1 = new Fiber(function () use (&$order) {
            $order[] = 'a';
            throw new RuntimeException('first');
        });

        $fiber2 = new Fiber(function () use (&$order) {
            $order[] = 'b';
        });

        $scheduler->track($fiber1, $future1);
        $scheduler->track($fiber2, $future2);
        $scheduler->enqueue($fiber1);
        $scheduler->enqueue($fiber2);

        $scheduler->tick(0);

        expect($order)->toBe(['a', 'b'])
            ->and($future1->error())->toBeInstanceOf(RuntimeException::class)
            ->and($future2->isCompleted())->toBeFalse();
    });

    it('untracked fiber does not reject the untracked future', function () {
        $scheduler = new ASKFiberScheduler(batchSize: 10);
        $trackedFuture = new ASKDeferred();
        $untrackedFuture = new ASKDeferred();

        $fiber = new Fiber(function () {
            throw new RuntimeException('fail');
        });

        $scheduler->track($fiber, $trackedFuture);
        $scheduler->enqueue($fiber);

        $scheduler->tick(0);

        expect($trackedFuture->isCompleted())->toBeTrue()
            ->and($trackedFuture->error())->toBeInstanceOf(RuntimeException::class)
            ->and($untrackedFuture->isCompleted())->toBeFalse();
    });

    it('rejects only the tracked future, not untracked ones', function () {
        $scheduler = new ASKFiberScheduler(batchSize: 10);
        $futureA = new ASKDeferred();
        $futureB = new ASKDeferred();

        $fiberA = new Fiber(function () {
            throw new RuntimeException('fail-a');
        });

        $fiberB = new Fiber(function () {
            throw new RuntimeException('fail-b');
        });

        $scheduler->track($fiberA, $futureA);
        $scheduler->track($fiberB, $futureB);
        $scheduler->enqueue($fiberA);
        $scheduler->enqueue($fiberB);

        $scheduler->tick(0);

        expect($futureA->error())->toBeInstanceOf(RuntimeException::class)
            ->and($futureB->error())->toBeInstanceOf(RuntimeException::class);
    });
});
