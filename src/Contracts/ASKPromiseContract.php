<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Contracts;

interface ASKPromiseContract
{
    public const PENDING = 'pending';
    public const FULFILLED = 'fulfilled';
    public const REJECTED = 'rejected';
    public const CANCELED = 'canceled';

    public function then(
        ?callable $onFulfilled = null,
        ?callable $onRejected = null,
    ): ASKPromiseContract;

    public function otherwise(callable $onRejected): ASKPromiseContract;

    public function wait(bool $unwrap = true): mixed;

    public function getState(): string;

    public function getValue(): mixed;

    public function getReason(): ?\Throwable;

    public function cancel(): void;
}
