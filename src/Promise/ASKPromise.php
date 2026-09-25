<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Promise;

use BAGArt\AsyncKernel\Contracts\ASKAwaitableContract;
use BAGArt\AsyncKernel\Contracts\ASKPromiseContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
use BAGArt\AsyncKernel\Exceptions\ASKInterruptException;
use BAGArt\AsyncKernel\Exceptions\ASKTechnicalException;
use Fiber;
use Throwable;

final class ASKPromise implements ASKPromiseContract, ASKAwaitableContract
{
    public const string PENDING = 'pending';
    public const string FULFILLED = 'fulfilled';
    public const string REJECTED = 'rejected';
    public const string CANCELED = 'canceled';

    private string $state = self::PENDING;

    private mixed $value = null;

    private ?Throwable $reason = null;

    /** @var list<callable> */
    private array $onFulfilled = [];

    /** @var list<callable> */
    private array $onRejected = [];

    /** @var list<ASKTickableContract> */
    private readonly array $tickables;

    private bool $flushing = false;

    /** @var list<Throwable> */
    private array $callbackErrors = [];

    public function __construct(ASKTickableContract ...$tickables)
    {
        $this->tickables = $tickables;
    }

    public static function resolved(mixed $value): self
    {
        $promise = new self();
        $promise->state = self::FULFILLED;
        $promise->value = $value;

        return $promise;
    }

    public static function rejected(Throwable $reason): self
    {
        $promise = new self();
        $promise->state = self::REJECTED;
        $promise->reason = $reason;

        return $promise;
    }

    public function getState(): string
    {
        $this->maybeTransition();

        return $this->state;
    }

    public function getValue(): mixed
    {
        $this->maybeTransition();

        if ($this->state !== self::FULFILLED) {
            throw new ASKTechnicalException('Promise is not fulfilled');
        }

        return $this->value;
    }

    public function getReason(): ?Throwable
    {
        $this->maybeTransition();

        return $this->reason;
    }

    public function resolve(mixed $value): void
    {
        if ($this->isSettled()) {
            return;
        }

        if ($value instanceof ASKPromiseContract) {
            $value->then(
                fn (mixed $v) => $this->resolve($v),
                fn (Throwable $e) => $this->reject($e),
            );

            return;
        }

        $this->state = self::FULFILLED;
        $this->value = $value;

        $this->flushFulfilled();
    }

    public function reject(Throwable $reason): void
    {
        if ($this->isSettled()) {
            return;
        }

        $this->state = self::REJECTED;
        $this->reason = $reason;

        $this->flushRejected();
    }

    public function cancel(): void
    {
        if ($this->isSettled()) {
            return;
        }

        $this->state = self::CANCELED;
        $this->reason = new ASKTechnicalException('Promise cancelled');

        $this->flushRejected();
    }

    private function isSettled(): bool
    {
        return $this->state !== self::PENDING;
    }

    public function then(
        ?callable $onFulfilled = null,
        ?callable $onRejected = null,
    ): ASKPromiseContract {
        $child = new self(...$this->tickables);

        $fulfillHandler = function (mixed $value) use ($onFulfilled, $child): void {
            if ($onFulfilled === null) {
                $child->resolve($value);

                return;
            }

            try {
                $child->resolve($onFulfilled($value));
            } catch (Throwable $e) {
                $child->reject($e);
            }
        };

        $rejectHandler = function (Throwable $reason) use ($onRejected, $child): void {
            if ($onRejected === null) {
                $child->reject($reason);

                return;
            }

            try {
                $child->resolve($onRejected($reason));
            } catch (Throwable $e) {
                $child->reject($e);
            }
        };

        $this->onFulfilled[] = $fulfillHandler;
        $this->onRejected[] = $rejectHandler;

        $this->flushIfSettled();

        return $child;
    }

    public function otherwise(callable $onRejected): ASKPromiseContract
    {
        return $this->then(null, $onRejected);
    }

    private function flushIfSettled(): void
    {
        if ($this->flushing) {
            return;
        }

        if ($this->state === self::FULFILLED) {
            $this->flushFulfilled();
        }

        if ($this->state === self::REJECTED || $this->state === self::CANCELED) {
            $this->flushRejected();
        }
    }

    private function flushFulfilled(): void
    {
        if ($this->flushing) {
            return;
        }

        $this->flushing = true;

        while ($this->onFulfilled !== []) {
            $callbacks = $this->onFulfilled;
            $this->onFulfilled = [];

            foreach ($callbacks as $fn) {
                try {
                    $fn($this->value);
                } catch (ASKInterruptException $e) {
                    $this->flushing = false;
                    throw $e;
                } catch (Throwable $e) {
                    $this->callbackErrors[] = $e;
                }
            }
        }

        $this->flushing = false;

        $this->maybeTransition();
    }

    private function flushRejected(): void
    {
        if ($this->flushing) {
            return;
        }

        $this->flushing = true;

        $callbacks = $this->onRejected;
        $this->onRejected = [];

        $originalReason = $this->reason;

        foreach ($callbacks as $fn) {
            try {
                $fn($originalReason);
            } catch (Throwable) {
                // Callback failure is logged/diagnosed elsewhere if needed.
                // The original rejection reason is immutable after rejection.
            }
        }

        $this->flushing = false;
    }

    private function maybeTransition(): void
    {
        if ($this->state === self::FULFILLED && $this->callbackErrors !== []) {
            $this->state = self::REJECTED;
            $this->reason = $this->callbackErrors[0];
            $this->callbackErrors = [];
            $this->flushRejected();
        }
    }

    public function await(bool $unwrap = true): mixed
    {
        if ($this->state !== self::PENDING) {
            return $this->unwrap($unwrap);
        }

        $fiber = Fiber::getCurrent();

        if (!$fiber) {
            return $this->wait($unwrap);
        }

        // Resume callbacks: these wake the suspended Fiber when the promise settles.
        // The return values from Fiber::resume() are unused by design — the Fiber
        // re-enters await() at the point of Fiber::suspend() and continues from there.
        $this->then(
            static function () use ($fiber): void {
                if ($fiber->isSuspended()) {
                    $fiber->resume();
                }
            },
            static function () use ($fiber): void {
                if ($fiber->isSuspended()) {
                    $fiber->resume();
                }
            },
        );

        while ($this->state === self::PENDING) {
            Fiber::suspend();
        }

        return $this->unwrap($unwrap);
    }

    private function unwrap(bool $unwrap = true): mixed
    {
        $this->maybeTransition();

        if (
            $unwrap
            && ($this->state === self::REJECTED || $this->state === self::CANCELED)
        ) {
            throw $this->reason;
        }

        return $this->value;
    }

    public function isCompleted(): bool
    {
        $this->maybeTransition();

        return $this->isSettled();
    }

    public function result(): mixed
    {
        $this->maybeTransition();

        if ($this->state === self::REJECTED || $this->state === self::CANCELED) {
            throw $this->reason;
        }

        return $this->value;
    }

    public function error(): ?Throwable
    {
        $this->maybeTransition();

        return $this->reason;
    }

    public function onCompleted(callable $callback): void
    {
        $this->then(
            fn (mixed $value) => $callback($this),
            fn (Throwable $reason) => $callback($this),
        );
    }

    public function wait(bool $unwrap = true): mixed
    {
        if ($this->tickables === []) {
            throw new ASKTechnicalException(
                '[ASKPromise::wait] No Fiber context. '
                .'Register the transport in AsyncKernel and call await() from within a Fiber, '
                .'or pass ASKTickableContract instances to the constructor.'
            );
        }

        while ($this->state === self::PENDING) {
            foreach ($this->tickables as $tickable) {
                $tickable->tick(0);
            }
        }

        return $this->unwrap($unwrap);
    }
}
