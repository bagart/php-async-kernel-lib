<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\Drivers\ASKFiberScheduler;
use BAGArt\AsyncKernel\Drivers\ASKFiberSchedulerSocketLease;

final class LeaseWarningRecorder
{
    /**
     * @return list<string>
     */
    public static function during(callable $fn): array
    {
        $captured = [];

        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            if (($errno & E_USER_WARNING) !== E_USER_WARNING) {
                return false;
            }

            $captured[] = $errstr;

            return true;
        });

        try {
            $fn();
        } finally {
            restore_error_handler();
        }

        return $captured;
    }
}

function captureLeaseWarnings(callable $fn): array
{
    $warnings = [];

    set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
        if ($errno === E_USER_WARNING) {
            $warnings[] = $errstr;

            return true;
        }

        return false;
    });

    try {
        $fn();
    } finally {
        restore_error_handler();
    }

    return $warnings;
}

describe('ASKFiberSchedulerSocketLease destructor (Q6)', function () {
    it('stays silent when the lease was released', function () {
        $scheduler = new ASKFiberScheduler();

        $warnings = LeaseWarningRecorder::during(function () use ($scheduler): void {
            $lease = new ASKFiberSchedulerSocketLease(
                schedulerRef: WeakReference::create($scheduler),
                socketId: 101,
            );
            $lease->release();
            unset($lease);
        });

        expect($warnings)->toBeEmpty();
    });

    it('stays silent when the lease was cancelled', function () {
        $scheduler = new ASKFiberScheduler();

        $warnings = LeaseWarningRecorder::during(function () use ($scheduler): void {
            $lease = new ASKFiberSchedulerSocketLease(
                schedulerRef: WeakReference::create($scheduler),
                socketId: 102,
            );
            $lease->cancel();
            unset($lease);
        });

        expect($warnings)->toBeEmpty();
    });

    it('stays silent after a scheduler-side watch → unwatch cycle', function () {
        $scheduler = new ASKFiberScheduler();

        $warnings = LeaseWarningRecorder::during(function () use ($scheduler): void {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

            $fiber = new Fiber(function () use ($scheduler, $pair): void {
                $lease = $scheduler->watchRead($pair[0]);
                $scheduler->unwatchRead($pair[0]);
                unset($lease);
            });

            $fiber->start();

            fclose($pair[0]);
            fclose($pair[1]);
        });

        expect($warnings)->toBeEmpty();
    });

    it('warns exactly once when the lease is abandoned', function () {
        $scheduler = new ASKFiberScheduler();

        $warnings = LeaseWarningRecorder::during(function () use ($scheduler): void {
            $lease = new ASKFiberSchedulerSocketLease(
                schedulerRef: WeakReference::create($scheduler),
                socketId: 103,
            );
            unset($lease);
        });

        expect($warnings)->toHaveCount(1);
        expect($warnings[0])->toContain('abandoned without release()');
    });
});

describe('ASKFiberSchedulerSocketLease lifecycle (H5)', function () {
    it('release() removes the registered read watch and is idempotent', function () {
        $scheduler = new ASKFiberScheduler();
        [$read, $write] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $lease = null;
        $fiber = new Fiber(function () use ($scheduler, $read, &$lease) {
            $lease = $scheduler->watchRead($read);
        });
        $fiber->start();

        expect($scheduler->isIdle())->toBeFalse();

        $lease->release();

        expect($scheduler->isIdle())->toBeTrue();

        $lease->release();

        expect($scheduler->isIdle())->toBeTrue();

        fclose($read);
        fclose($write);
    });

    it('release() removes the registered write watch', function () {
        $scheduler = new ASKFiberScheduler();
        [$read, $write] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $lease = null;
        $fiber = new Fiber(function () use ($scheduler, $write, &$lease) {
            $lease = $scheduler->watchWrite($write);
        });
        $fiber->start();

        expect($scheduler->isIdle())->toBeFalse();

        $lease->release();

        expect($scheduler->isIdle())->toBeTrue();

        fclose($read);
        fclose($write);
    });

    it('keeps the lease owned by the scheduler while the watch is registered', function () {
        $scheduler = new ASKFiberScheduler();
        [$read, $write] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $lease = null;
        $fiber = new Fiber(function () use ($scheduler, $read, &$lease) {
            $lease = $scheduler->watchRead($read);
        });
        $fiber->start();

        $leases = (fn (): array => $this->leases)->call($scheduler);

        expect($leases)->toContain($lease);

        $lease->release();

        $leases = (fn (): array => $this->leases)->call($scheduler);

        expect($leases)->not->toContain($lease);

        fclose($read);
        fclose($write);
    });

    it('warns when a lease is abandoned while the scheduler is still alive', function () {
        $warnings = captureLeaseWarnings(function (): void {
            $scheduler = new ASKFiberScheduler();
            $lease = new ASKFiberSchedulerSocketLease(WeakReference::create($scheduler), 7);

            unset($lease);

            expect($scheduler)->toBeInstanceOf(ASKFiberScheduler::class);
        });

        expect($warnings)->toHaveCount(1);
        expect($warnings[0])->toContain('was abandoned without release()');
        expect($warnings[0])->toContain('Socket watch may leak');
    });

    it('does not warn once the lease has been released', function () {
        $warnings = captureLeaseWarnings(function (): void {
            $scheduler = new ASKFiberScheduler();
            $lease = new ASKFiberSchedulerSocketLease(WeakReference::create($scheduler), 11);

            $lease->release();
            $lease->cancel();
            unset($lease);

            expect($scheduler)->toBeInstanceOf(ASKFiberScheduler::class);
        });

        expect($warnings)->toBeEmpty();
    });

    it('stays silent and safe when the scheduler was garbage collected', function () {
        $warnings = captureLeaseWarnings(function (): void {
            $scheduler = new ASKFiberScheduler();
            $lease = new ASKFiberSchedulerSocketLease(WeakReference::create($scheduler), 21);

            $scheduler = null;
            gc_collect_cycles();

            $lease->release();
            unset($lease);
        });

        expect($warnings)->toBeEmpty();
    });
});
