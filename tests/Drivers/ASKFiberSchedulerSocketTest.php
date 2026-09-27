<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\Contracts\ASKResourceLease;
use BAGArt\AsyncKernel\Drivers\ASKFiberScheduler;

describe('ASKFiberScheduler socket watching', function () {
    it('watchRead requires a running Fiber', function () {
        $scheduler = new ASKFiberScheduler();
        [$read, $write] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        expect(fn () => $scheduler->watchRead($read))
            ->toThrow(RuntimeException::class, 'watchRead requires a running Fiber');

        expect($scheduler->isIdle())->toBeTrue();

        fclose($read);
        fclose($write);
    });

    it('watchWrite requires a running Fiber', function () {
        $scheduler = new ASKFiberScheduler();
        [$read, $write] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        expect(fn () => $scheduler->watchWrite($write))
            ->toThrow(RuntimeException::class, 'watchWrite requires a running Fiber');

        expect($scheduler->isIdle())->toBeTrue();

        fclose($read);
        fclose($write);
    });

    it('registers a read watch from inside a Fiber and release() unwatches it', function () {
        $scheduler = new ASKFiberScheduler();
        [$read, $write] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $lease = null;
        $fiber = new Fiber(function () use ($scheduler, $read, &$lease) {
            $lease = $scheduler->watchRead($read);
        });
        $fiber->start();

        expect($lease)->toBeInstanceOf(ASKResourceLease::class);
        expect($scheduler->queueSize())->toBe(0);
        expect($scheduler->isIdle())->toBeFalse();
        // Queue is empty: pressure comes from the socket watch alone.
        expect($scheduler->pressure())->toBeGreaterThan(0);

        $lease->release();

        expect($scheduler->isIdle())->toBeTrue();
        expect($scheduler->pressure())->toBe(0);

        $lease->release();
        expect($scheduler->isIdle())->toBeTrue();

        fclose($read);
        fclose($write);
    });

    it('unwatchRead clears the read watch', function () {
        $scheduler = new ASKFiberScheduler();
        [$read, $write] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $lease = null;
        $fiber = new Fiber(function () use ($scheduler, $read, &$lease) {
            $lease = $scheduler->watchRead($read);
        });
        $fiber->start();

        expect($scheduler->isIdle())->toBeFalse();

        $scheduler->unwatchRead($read);

        expect($scheduler->isIdle())->toBeTrue();
        expect($scheduler->pressure())->toBe(0);

        $lease->release();

        fclose($read);
        fclose($write);
    });

    it('unwatchWrite clears the write watch', function () {
        $scheduler = new ASKFiberScheduler();
        [$read, $write] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $lease = null;
        $fiber = new Fiber(function () use ($scheduler, $write, &$lease) {
            $lease = $scheduler->watchWrite($write);
        });
        $fiber->start();

        expect($scheduler->isIdle())->toBeFalse();

        $scheduler->unwatchWrite($write);

        expect($scheduler->isIdle())->toBeTrue();

        $lease->release();

        fclose($read);
        fclose($write);
    });

    it('unwatch by resource id removes only the matching watch', function () {
        $scheduler = new ASKFiberScheduler();
        [$firstRead, $firstWrite] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        [$secondRead, $secondWrite] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $leases = [];
        $fiber = new Fiber(function () use ($scheduler, $firstRead, $secondRead, &$leases) {
            $leases[] = $scheduler->watchRead($firstRead);
            $leases[] = $scheduler->watchRead($secondRead);
        });
        $fiber->start();

        $scheduler->unwatchReadByResourceId((int) $secondRead);
        expect($scheduler->isIdle())->toBeFalse();

        $scheduler->unwatchReadByResourceId((int) $firstRead);
        expect($scheduler->isIdle())->toBeTrue();

        foreach ($leases as $lease) {
            $lease->release();
        }

        fclose($firstRead);
        fclose($firstWrite);
        fclose($secondRead);
        fclose($secondWrite);
    });

    it('tick wakes a Fiber waiting on a readable socket', function () {
        $scheduler = new ASKFiberScheduler(batchSize: 10);
        [$read, $write] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $woken = false;
        $lease = null;
        $fiber = new Fiber(function () use ($scheduler, $read, &$lease, &$woken) {
            $lease = $scheduler->watchRead($read);
            Fiber::suspend();
            $woken = true;
            $lease->release();
        });
        $fiber->start();

        expect($scheduler->isIdle())->toBeFalse();

        fwrite($write, 'ping');

        // Queue is empty → the tick runs stream_select and re-queues the waiter.
        $scheduler->tick(0);
        expect($scheduler->queueSize())->toBe(1);

        $scheduler->tick(0);
        expect($woken)->toBeTrue();
        expect($scheduler->queueSize())->toBe(0);
        expect($scheduler->isIdle())->toBeTrue();

        fclose($read);
        fclose($write);
    });

    it('tick wakes a Fiber waiting on a writable socket', function () {
        $scheduler = new ASKFiberScheduler(batchSize: 10);
        [$read, $write] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $woken = false;
        $lease = null;
        $fiber = new Fiber(function () use ($scheduler, $write, &$lease, &$woken) {
            $lease = $scheduler->watchWrite($write);
            Fiber::suspend();
            $woken = true;
            $lease->release();
        });
        $fiber->start();

        $scheduler->tick(0);
        expect($scheduler->queueSize())->toBe(1);

        $scheduler->tick(0);
        expect($woken)->toBeTrue();
        expect($scheduler->queueSize())->toBe(0);
        expect($scheduler->isIdle())->toBeTrue();

        fclose($read);
        fclose($write);
    });

    it('poll leaves waiters asleep while no socket is ready', function () {
        $scheduler = new ASKFiberScheduler();
        [$read, $write] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $lease = null;
        $fiber = new Fiber(function () use ($scheduler, $read, &$lease) {
            $lease = $scheduler->watchRead($read);
        });
        $fiber->start();

        (new ReflectionMethod($scheduler, 'pollSockets'))->invoke($scheduler, 0, 0);

        expect($scheduler->queueSize())->toBe(0);

        $lease->release();

        fclose($read);
        fclose($write);
    });

    it('forceStop clears pending socket watches', function () {
        $scheduler = new ASKFiberScheduler(batchSize: 1);
        [$read, $write] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $lease = null;
        $scheduler->enqueue(function () use ($scheduler, $read, &$lease) {
            $lease = $scheduler->watchRead($read);
            Fiber::suspend();
        });
        $scheduler->tick(0);

        expect($scheduler->isIdle())->toBeFalse();

        $scheduler->forceStop();

        expect($scheduler->isIdle())->toBeTrue();
        expect($scheduler->queueSize())->toBe(0);
        expect($scheduler->pressure())->toBe(0);

        $lease->release();

        fclose($read);
        fclose($write);
    });

    it('pressure caps socket pressure at 100', function () {
        $scheduler = new ASKFiberScheduler();
        $leases = [];
        $pairs = [];

        for ($i = 0; $i < 30; $i++) {
            [$read, $write] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $pairs[] = [$read, $write];

            $fiber = new Fiber(function () use ($scheduler, $read, $write, &$leases) {
                $leases[] = $scheduler->watchRead($read);
                $leases[] = $scheduler->watchWrite($write);
            });
            $fiber->start();
        }

        // 60 watched sockets → raw 120 → capped at 100.
        expect($scheduler->pressure())->toBe(100);
        expect($scheduler->isIdle())->toBeFalse();

        foreach ($leases as $lease) {
            $lease->release();
        }

        expect($scheduler->pressure())->toBe(0);
        expect($scheduler->isIdle())->toBeTrue();

        foreach ($pairs as [$read, $write]) {
            fclose($read);
            fclose($write);
        }
    });

    it('pressure takes the stronger of socket and queue sources', function () {
        $scheduler = new ASKFiberScheduler();
        [$read, $write] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $leases = [];
        $fiber = new Fiber(function () use ($scheduler, $read, $write, &$leases) {
            $leases[] = $scheduler->watchRead($read);
            $leases[] = $scheduler->watchWrite($write);
        });
        $fiber->start();

        for ($i = 0; $i < 10; $i++) {
            $scheduler->enqueue(function () {
                Fiber::suspend();
            });
        }

        // 4 socket pressure vs 10 queue pressure → 10.
        expect($scheduler->pressure())->toBe(10);

        foreach ($leases as $lease) {
            $lease->release();
        }

        expect($scheduler->pressure())->toBe(10);

        fclose($read);
        fclose($write);
    });
});
