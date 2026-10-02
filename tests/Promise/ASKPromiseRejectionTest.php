<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\Promise\ASKPromise;

describe('ASKPromise rejection reason preservation (C4)', function () {
    it('preserves original rejection reason when first callback throws', function () {
        $originalReason = new RuntimeException('original failure');

        $promise = ASKPromise::rejected($originalReason);

        $promise->then(null, function () {
            throw new LogicException('callback error');
        });

        expect($promise->getState())->toBe(ASKPromise::REJECTED);
        expect($promise->getReason())->toBe($originalReason);
    });

    it('preserves original reason when multiple callbacks throw', function () {
        $originalReason = new RuntimeException('original');

        $promise = ASKPromise::rejected($originalReason);

        $promise->then(null, function () {
            throw new LogicException('first callback error');
        });

        $promise->then(null, function () {
            throw new LogicException('second callback error');
        });

        expect($promise->getReason())->toBe($originalReason);
    });

    it('still invokes subsequent callbacks after a callback throws', function () {
        $originalReason = new RuntimeException('original');
        $secondCallbackInvoked = false;

        $promise = ASKPromise::rejected($originalReason);

        $promise->then(null, function () {
            throw new LogicException('first callback error');
        });

        $promise->then(null, function ($reason) use (&$secondCallbackInvoked, $originalReason) {
            expect($reason)->toBe($originalReason);
            $secondCallbackInvoked = true;
        });

        expect($secondCallbackInvoked)->toBeTrue();
        expect($promise->getReason())->toBe($originalReason);
    });

    it('passes original reason to callbacks, not a derived one', function () {
        $originalReason = new RuntimeException('original');
        $receivedReason = null;

        $promise = ASKPromise::rejected($originalReason);

        $promise->then(null, function ($reason) use (&$receivedReason) {
            $receivedReason = $reason;
            throw new LogicException('callback error');
        });

        expect($receivedReason)->toBe($originalReason);
        expect($promise->getReason())->toBe($originalReason);
    });

    it('fulfilled callback throwing does not corrupt pending rejection', function () {
        $promise = new ASKPromise();

        $child = $promise->then(function ($value) {
            throw new LogicException('transform error');
        });

        $promise->resolve('ok');

        expect($child)->toBeInstanceOf(ASKPromise::class);
        expect($child->getState())->toBe(ASKPromise::REJECTED);
        expect($child->getReason())->toBeInstanceOf(LogicException::class);
    });
});
