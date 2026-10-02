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
