<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel;

use BAGArt\AsyncKernel\Exceptions\ASKTechnicalException;
use BAGArt\AsyncKernel\Promise\Awaitables\ASKSleepAwaitable;
use BAGArt\AsyncKernel\Timer\ASKTimer;

final class ASK
{
    private static ?ASKTimer $timer = null;

    /**
     * Register the process-wide timer backing {@see sleep()}.
     *
     * Intentional static side-effect: ASK is a facade over kernel-owned state,
     * so exactly one timer is reachable per process. AsyncKernel calls this
     * from its constructor (last constructed kernel wins).
     *
     * Constraints:
     * - one AsyncKernel per process is the supported setup; it owns the facade
     *   timer for the lifetime of the process;
     * - constructing a second kernel silently re-points ASK::sleep() to the
     *   new kernel's timer — in tests, construct the kernel that should own
     *   the facade last, or re-wire explicitly via this method afterwards.
     */
    public static function setTimer(ASKTimer $timer): void
    {
        self::$timer = $timer;
    }

    public static function sleep(int $milliseconds): ASKSleepAwaitable
    {
        if (self::$timer === null) {
            throw new ASKTechnicalException(
                'ASK::sleep() requires an ASKTimer. '
                .'AsyncKernel registers one by default; '
                .'in tests call ASK::setTimer(new ASKTimer()) first.'
            );
        }

        return self::$timer->sleep($milliseconds);
    }
}
