<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Contracts;

use Throwable;

interface ASKAwaitableContract
{
    public function isCompleted(): bool;

    public function result(): mixed;

    public function error(): ?Throwable;

    public function onCompleted(callable $callback): void;

    public function await(): mixed;
}
