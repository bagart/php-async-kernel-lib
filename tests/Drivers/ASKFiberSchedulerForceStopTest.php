<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\Drivers\ASKFiberScheduler;
use BAGArt\AsyncKernel\Exceptions\ASKForceShutdownException;

describe('ASKFiberScheduler forceStop', function () {
    it('leaves an empty scheduler idle', function () {
        $scheduler = new ASKFiberScheduler();

        $scheduler->forceStop();

        expect($scheduler->isIdle())->toBeTrue()
            ->and($scheduler->queueSize())->toBe(0)
            ->and($scheduler->pressure())->toBe(0);
    });

    it('invokes the onFiberForceStopped callback with the force shutdown exception', function () {
        $scheduler = new ASKFiberScheduler(batchSize: 1);
        $scheduler->enqueue(function () {
            Fiber::suspend();
        });
        $scheduler->tick(0);

        expect($scheduler->queueSize())->toBe(1);

        $seen = [];
        $scheduler->forceStop(function (Fiber $fiber, Throwable $e) use (&$seen) {
            $seen[] = [$fiber, $e];
        });

        expect($seen)->not->toBeEmpty()
            ->and($seen[0][0])->toBeInstanceOf(Fiber::class)
            ->and($seen[0][1])->toBeInstanceOf(ASKForceShutdownException::class)
            ->and($scheduler->isIdle())->toBeTrue()
            ->and($scheduler->queueSize())->toBe(0);
    });

    it('drains a fiber that swallows the force shutdown exception', function () {
        $scheduler = new ASKFiberScheduler(batchSize: 1);
        $swallowed = false;

        $scheduler->enqueue(function () use (&$swallowed) {
            try {
                Fiber::suspend();
            } catch (ASKForceShutdownException) {
                $swallowed = true;
            }
        });
        $scheduler->tick(0);

        $scheduler->forceStop();

        expect($swallowed)->toBeTrue()
            ->and($scheduler->isIdle())->toBeTrue()
            ->and($scheduler->queueSize())->toBe(0);
    });

    it('re-ticks a fiber that suspends again after catching the shutdown exception', function () {
        $scheduler = new ASKFiberScheduler(batchSize: 1);
        $finished = false;

        $scheduler->enqueue(function () use (&$finished) {
            try {
                Fiber::suspend();
            } catch (ASKForceShutdownException) {
                Fiber::suspend();
            }
            $finished = true;
        });
        $scheduler->tick(0);

        $scheduler->forceStop();

        expect($finished)->toBeTrue()
            ->and($scheduler->isIdle())->toBeTrue()
            ->and($scheduler->queueSize())->toBe(0);
    });

    it('starts fibers that never ran while draining', function () {
        $scheduler = new ASKFiberScheduler();
        $started = false;

        $fiber = new Fiber(function () use (&$started) {
            $started = true;
        });
        $scheduler->enqueue($fiber);

        $scheduler->forceStop();

        expect($started)->toBeTrue()
            ->and($scheduler->isIdle())->toBeTrue()
            ->and($scheduler->queueSize())->toBe(0);
    });

    it('keeps the scheduler reusable after a force stop', function () {
        $scheduler = new ASKFiberScheduler(batchSize: 10);

        $scheduler->enqueue(function () {
            Fiber::suspend();
        });
        $scheduler->tick(0);
        $scheduler->forceStop();

        $ran = false;
        $scheduler->enqueue(function () use (&$ran) {
            $ran = true;
        });
        $scheduler->tick(0);

        expect($ran)->toBeTrue()
            ->and($scheduler->isIdle())->toBeTrue()
            ->and($scheduler->queueSize())->toBe(0);
    });
});
