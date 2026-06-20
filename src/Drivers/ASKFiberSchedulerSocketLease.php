<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Drivers;

use BAGArt\AsyncKernel\Contracts\ASKResourceLease;
use WeakReference;

/**
 * A lightweight lease that knows only the scheduler and socket ID.
 *
 * Does NOT hold a WeakReference to the Fiber (ChatGPT fix #2).
 * Does NOT fclose() the resource in __destruct() (ChatGPT fix #3).
 * The resource owner (Fiber) is responsible for closing the resource.
 */
final class ASKFiberSchedulerSocketLease implements ASKResourceLease
{
    private bool $released = false;

    public function __construct(
        private WeakReference $schedulerRef,
        private int $socketId,
        private bool $isWrite = false,
    ) {
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;

        $scheduler = $this->schedulerRef->get();
        if ($scheduler !== null) {
            if ($this->isWrite) {
                $scheduler->unwatchWriteByResourceId($this->socketId);
            } else {
                $scheduler->unwatchReadByResourceId($this->socketId);
            }
        }
    }

    public function cancel(): void
    {
        $this->release();
    }
}
