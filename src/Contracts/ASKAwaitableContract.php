<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Contracts;

use Throwable;

interface ASKAwaitableContract
{
    /**
     * Whether the awaitable has settled (resolved or rejected).
     */
    public function isCompleted(): bool;

    /**
     * Settled value.
     *
     * Contract: when the awaitable was rejected, result() rethrows the stored
     * error (see error()) instead of returning null. Inspect error() first if
     * you need failure handling without exceptions:
     *
     *     $value = $awaitable->error() === null ? $awaitable->result() : null;
     */
    public function result(): mixed;

    /**
     * The stored rejection, or null when there is none.
     *
     * result() throws this error rather than returning it, so a rejected
     * awaitable must be inspected via error() before result() is called.
     */
    public function error(): ?Throwable;

    /**
     * Runs $callback once the awaitable settles — immediately if it already has.
     */
    public function onCompleted(callable $callback): void;

    /**
     * Waits for settlement and returns the value.
     *
     * Inside a Fiber it suspends until resolved; outside a Fiber it throws a
     * RuntimeException (unless the subclass provides a synchronous fallback).
     * A rejected awaitable makes await() throw the same error error() returns.
     */
    public function await(): mixed;
}
