<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel;

use BAGArt\AsyncKernel\Contracts\ASKAwaitableContract;
use Fiber;
use RuntimeException;
use Throwable;

abstract class ASKAwaitable implements ASKAwaitableContract
{
    private bool $completed = false;

    private mixed $result = null;

    private ?Throwable $error = null;

    /** @var callable[] */
    private array $callbacks = [];

    final public function isCompleted(): bool
    {
        return $this->completed;
    }

    /**
     * Settled value.
     *
     * @see ASKAwaitableContract::result() — rethrows the stored error on a
     *      rejected awaitable; call error() first when throwing is unwanted.
     */
    final public function result(): mixed
    {
        if ($this->error) {
            throw $this->error;
        }

        return $this->result;
    }

    /**
     * The stored rejection, or null when there is none.
     *
     * result() throws this error instead of returning it — inspect error()
     * (or isCompleted() + error()) before calling result().
     */
    final public function error(): ?Throwable
    {
        return $this->error;
    }

    final public function onCompleted(callable $callback): void
    {
        if ($this->completed) {
            $callback();

            return;
        }

        $this->callbacks[] = $callback;
    }

    /**
     * Suspends the current Fiber until settlement and returns the value.
     *
     * Outside a Fiber a RuntimeException is thrown. A rejected awaitable
     * makes await() throw the error reported by error() (via result()).
     */
    public function await(): mixed
    {
        if ($this->completed) {
            return $this->result();
        }

        $fiber = Fiber::getCurrent();

        if (!$fiber) {
            throw new RuntimeException(
                'await() may only be called inside Fiber'
            );
        }

        $this->onCompleted(
            static function () use ($fiber): void {
                if ($fiber->isSuspended()) {
                    $fiber->resume();
                }
            }
        );

        Fiber::suspend();

        return $this->result();
    }

    protected function resolve(mixed $value = null): void
    {
        if ($this->completed) {
            return;
        }

        $this->completed = true;
        $this->result = $value;

        foreach ($this->callbacks as $callback) {
            $callback();
        }

        $this->callbacks = [];
    }

    final protected function reject(Throwable $e): void
    {
        if ($this->completed) {
            return;
        }

        $this->completed = true;
        $this->error = $e;

        foreach ($this->callbacks as $callback) {
            $callback();
        }

        $this->callbacks = [];
    }
}
