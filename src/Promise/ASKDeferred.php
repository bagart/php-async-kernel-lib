<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Promise;

use BAGArt\AsyncKernel\Contracts\ASKAwaitableContract;
use Throwable;

// Use our Promise

final class ASKDeferred implements ASKAwaitableContract
{
    private ASKPromise $promise;

    public function __construct()
    {
        // A promise is created internally (resolve/reject methods must be accessible
        // via protected/package scope or via a closure constructor)
        $this->promise = new ASKPromise();
    }

    public function promise(): ASKPromise
    {
        return $this->promise;
    }

    public function resolve(mixed $value = null): void
    {
        $this->promise->resolve($value);
    }

    public function reject(Throwable $e): void
    {
        $this->promise->reject($e);
    }

    public function isCompleted(): bool
    {
        return $this->promise->isCompleted();
    }

    public function result(): mixed
    {
        return $this->promise->result();
    }

    public function error(): ?Throwable
    {
        return $this->promise->error();
    }

    public function onCompleted(callable $callback): void
    {
        $this->promise->onCompleted($callback);
    }

    public function await(): mixed
    {
        return $this->promise->await();
    }
}
