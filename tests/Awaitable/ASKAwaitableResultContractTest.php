<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\ASKAwaitable;
use BAGArt\AsyncKernel\Contracts\ASKAwaitableContract;

/**
 * Test double exposing the protected resolve()/reject() settle hooks.
 */
function askAwaitableProbe(): ASKAwaitable
{
    return new class extends ASKAwaitable {
        public function doResolve(mixed $value = null): void
        {
            $this->resolve($value);
        }

        public function doReject(Throwable $e): void
        {
            $this->reject($e);
        }
    };
}

describe('ASKAwaitable result()/error() contract (M6)', function () {
    it('exposes the documented contract through ASKAwaitableContract', function () {
        expect(askAwaitableProbe())->toBeInstanceOf(ASKAwaitableContract::class);
    });

    it('returns the value and no error for a resolved awaitable', function () {
        $awaitable = askAwaitableProbe();
        expect($awaitable->isCompleted())->toBeFalse();

        $awaitable->doResolve('settled-value');

        expect($awaitable->isCompleted())->toBeTrue();
        expect($awaitable->error())->toBeNull();
        expect($awaitable->result())->toBe('settled-value');
    });

    it('rethrows the very error error() returns when result() is called', function () {
        $awaitable = askAwaitableProbe();
        $reason = new RuntimeException('stored-failure');

        $awaitable->doReject($reason);

        expect($awaitable->isCompleted())->toBeTrue();
        expect($awaitable->error())->toBe($reason);

        $thrown = null;

        try {
            $awaitable->result();
        } catch (Throwable $e) {
            $thrown = $e;
        }

        expect($thrown)->toBe($reason);
    });

    it('supports the documented check-error-first ordering without exceptions', function () {
        $awaitable = askAwaitableProbe();
        $reason = new LogicException('handled-without-throwing');
        $awaitable->doReject($reason);

        $error = $awaitable->error();
        $result = $error === null ? $awaitable->result() : null;

        expect($error)->toBe($reason);
        expect($result)->toBeNull();
    });

    it('throws the stored error from await() inside a Fiber', function () {
        $awaitable = askAwaitableProbe();
        $reason = new RuntimeException('rejected-before-await');
        $caught = null;

        $fiber = new Fiber(function () use ($awaitable, &$caught) {
            try {
                $awaitable->await();
            } catch (RuntimeException $e) {
                $caught = $e;
            }
        });

        $fiber->start();
        $awaitable->doReject($reason);

        expect($caught)->toBe($reason);
    });

    it('returns the value from await() inside a Fiber when resolved', function () {
        $awaitable = askAwaitableProbe();
        $result = null;

        $fiber = new Fiber(function () use ($awaitable, &$result) {
            $result = $awaitable->await();
        });

        $fiber->start();
        $awaitable->doResolve('fiber-value');

        expect($result)->toBe('fiber-value');
    });

    it('throws the stored error from await() on an already rejected awaitable', function () {
        $awaitable = askAwaitableProbe();
        $reason = new RuntimeException('settled-before-await');
        $awaitable->doReject($reason);

        expect(fn () => $awaitable->await())->toThrow(RuntimeException::class, 'settled-before-await');
        expect($awaitable->error())->toBe($reason);
    });

    it('throws a RuntimeException from await() outside a Fiber', function () {
        $awaitable = askAwaitableProbe();

        expect(fn () => $awaitable->await())->toThrow(RuntimeException::class, 'await() may only be called inside Fiber');
    });

    it('settles only once — later resolve/reject attempts are ignored', function () {
        $awaitable = askAwaitableProbe();
        $reason = new RuntimeException('first');

        $awaitable->doReject($reason);
        $awaitable->doResolve('second');
        $awaitable->doReject(new RuntimeException('third'));

        expect($awaitable->error())->toBe($reason);
        expect(fn () => $awaitable->result())->toThrow(RuntimeException::class, 'first');
    });

    it('invokes onCompleted callbacks for a rejected awaitable as well', function () {
        $awaitable = askAwaitableProbe();
        $calls = 0;

        $awaitable->onCompleted(function () use (&$calls): void {
            $calls++;
        });
        expect($calls)->toBe(0);

        $awaitable->doReject(new RuntimeException('late-failure'));
        expect($calls)->toBe(1);

        $awaitable->onCompleted(function () use (&$calls): void {
            $calls++;
        });
        expect($calls)->toBe(2);
    });
});
