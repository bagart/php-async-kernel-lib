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

    final public function result(): mixed
    {
        if ($this->error) {
            throw $this->error;
        }

        return $this->result;
    }

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
