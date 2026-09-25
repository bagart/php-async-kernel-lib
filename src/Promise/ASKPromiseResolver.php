<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Promise;

use BAGArt\AsyncKernel\Contracts\ASKPromiseContract;
use BAGArt\AsyncKernel\Contracts\Promise\ASKPromiseResolverContract;
use BAGArt\AsyncKernel\Exceptions\ASKAggregateException;
use BAGArt\AsyncKernel\Exceptions\ASKException;
use BAGArt\AsyncKernel\Exceptions\ASKTechnicalException;
use Fiber;

final class ASKPromiseResolver implements ASKPromiseResolverContract
{
    /** @var list<array{promise: ASKPromiseContract, fiber: Fiber, deadline: ?float}> */
    private array $awaiting = [];

    public function isReady(): bool
    {
        return Fiber::getCurrent() !== null;
    }

    public function await(
        ASKPromiseContract $promise,
        int $timeout = 0,
    ): mixed {
        if ($promise->getState() !== ASKPromiseContract::PENDING) {
            return $this->unwrap($promise);
        }

        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            throw new ASKTechnicalException(
                '[PromiseResolver::await] await() must be called inside Fiber'
            );
        }

        $this->register($promise, $fiber, $timeout);

        return Fiber::suspend();
    }

    public function wait(ASKPromiseContract $promise): void
    {
        if ($promise->getState() !== ASKPromiseContract::PENDING) {
            return;
        }

        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            throw new ASKTechnicalException(
                '[PromiseResolver::wait] wait() must be called inside Fiber'
            );
        }

        $this->register($promise, $fiber, 0);

        try {
            Fiber::suspend();
        } catch (\Throwable $e) {
            error_log("[ASK][WARNING] Promise rejection callback error: " . $e->getMessage());
            throw $e;
        }
    }

    public function tick(int $systemPressure): void
    {
        if ($this->awaiting === []) {
            return;
        }

        $pending = [];
        $ready = [];

        $now = microtime(true);

        foreach ($this->awaiting as $slot) {
            $state = $slot['promise']->getState();

            if ($state === ASKPromiseContract::PENDING) {
                if ($slot['deadline'] !== null && $now >= $slot['deadline']) {
                    $slot['outcome'] = 'timeout';
                    $ready[] = $slot;
                } else {
                    $pending[] = $slot;
                }
            } else {
                $slot['outcome'] = $state;
                $ready[] = $slot;
            }
        }

        $this->awaiting = $pending;

        /** @var list<\Throwable> $caughtExceptions */
        $caughtExceptions = [];

        foreach ($ready as $slot) {
            $fiber = $slot['fiber'];

            if (!$fiber->isSuspended()) {
                continue;
            }

            try {
                if ($slot['outcome'] === ASKPromiseContract::FULFILLED) {
                    $fiber->resume($slot['promise']->getValue());
                } else {
                    $reason = $slot['outcome'] === 'timeout'
                        ? new ASKException('[PromiseResolver::await] Promise timeout')
                        : ($slot['promise']->getReason()
                            ?? new ASKException('[PromiseResolver] Rejected without reason'));

                    $fiber->throw($reason);
                }
            } catch (\Throwable $e) {
                $caughtExceptions[] = $e;
            }
        }

        if ($caughtExceptions !== []) {
            if (count($caughtExceptions) === 1) {
                throw $caughtExceptions[0];
            }

            throw new ASKAggregateException($caughtExceptions);
        }
    }

    public function pressure(): int
    {
        return 0;
    }

    public function isIdle(): bool
    {
        return $this->awaiting === [];
    }

    public function queueSize(): int
    {
        return count($this->awaiting);
    }

    private function register(
        ASKPromiseContract $promise,
        Fiber $fiber,
        int $timeout,
    ): void {
        $this->awaiting[] = [
            'promise' => $promise,
            'fiber' => $fiber,
            'deadline' => $timeout > 0 ? microtime(true) + $timeout : null,
        ];
    }

    private function unwrap(ASKPromiseContract $p): mixed
    {
        if ($p->getState() === ASKPromiseContract::REJECTED) {
            throw $p->getReason()
                ?? new ASKException('[PromiseResolver] Rejected without reason');
        }

        return $p->getValue();
    }
}
