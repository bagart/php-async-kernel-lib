<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\Lockers\CacheLocker;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 fake with atomic add() that simulates concurrent access.
 */
function makeAtomicCache(array &$store): CacheInterface
{
    return new class ($store) implements CacheInterface {
        /** @param  array<string, mixed>  $store */
        public function __construct(
            private array &$store,
        ) {
        }

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->store[$key] ?? $default;
        }

        public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
        {
            $this->store[$key] = $value;

            return true;
        }

        public function delete(string $key): bool
        {
            unset($this->store[$key]);

            return true;
        }

        public function clear(): bool
        {
            $this->store = [];

            return true;
        }

        public function getMultiple(iterable $keys, mixed $default = null): iterable
        {
            return [];
        }

        public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
        {
            return true;
        }

        public function deleteMultiple(iterable $keys): bool
        {
            return true;
        }

        public function has(string $key): bool
        {
            return array_key_exists($key, $this->store);
        }

        /**
         * Atomic set-if-not-exists — simulates the mutual exclusion guarantee.
         */
        public function add(string $key, mixed $value, int $ttl = 0): bool
        {
            if ($this->has($key)) {
                return false;
            }

            return $this->set($key, $value, $ttl);
        }
    };
}

describe('CacheLocker TOCTOU race protection', function () {
    it('only one of two concurrent acquirers succeeds with atomic add()', function () {
        $store = [];
        $cache = makeAtomicCache($store);

        $lockerA = new CacheLocker($cache);
        $lockerB = new CacheLocker($cache);

        $acquiredA = $lockerA->acquire('resource:1');
        $acquiredB = $lockerB->acquire('resource:1');

        // With atomic add(), exactly one must fail
        expect($acquiredA)->toBeTrue();
        expect($acquiredB)->toBeFalse();
    });

    it('second acquirer succeeds after release by first', function () {
        $store = [];
        $cache = makeAtomicCache($store);

        $lockerA = new CacheLocker($cache);
        $lockerB = new CacheLocker($cache);

        $lockerA->acquire('resource:1');
        $lockerA->release('resource:1');

        $acquiredB = $lockerB->acquire('resource:1');

        expect($acquiredB)->toBeTrue();
    });

    it('release by wrong owner does not release the lock', function () {
        $store = [];
        $cache = makeAtomicCache($store);

        $lockerA = new CacheLocker($cache);
        $lockerB = new CacheLocker($cache);

        $lockerA->acquire('resource:1');
        $lockerB->releaseWithOwner('resource:1', 'wrong-owner');

        // Lock should still be held by A
        $lockerC = new CacheLocker($cache);
        expect($lockerC->acquire('resource:1'))->toBeFalse();
    });

    it('release with matching owner succeeds', function () {
        $store = [];
        $cache = makeAtomicCache($store);

        $locker = new CacheLocker($cache);
        $locker->acquire('resource:1');

        // Get the token via reflection to verify owner check
        $ref = new ReflectionProperty($locker, 'token');
        $token = $ref->getValue($locker);

        $locker->releaseWithOwner('resource:1', $token);

        $locker2 = new CacheLocker($cache);
        expect($locker2->acquire('resource:1'))->toBeTrue();
    });

    it('non-atomic cache throws TOCTOU exception', function () {
        $store = [];
        $cache = makeAtomicCache($store);

        // Create a cache WITHOUT add() to simulate non-atomic backend
        $nonAtomicCache = new class ($store) implements CacheInterface {
            /** @param  array<string, mixed>  $store */
            public function __construct(
                private array &$store,
            ) {
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->store[$key] ?? $default;
            }

            public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
            {
                $this->store[$key] = $value;

                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->store[$key]);

                return true;
            }

            public function clear(): bool
            {
                $this->store = [];

                return true;
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                return [];
            }

            public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
            {
                return true;
            }

            public function deleteMultiple(iterable $keys): bool
            {
                return true;
            }

            public function has(string $key): bool
            {
                return array_key_exists($key, $this->store);
            }
            // Deliberately no add() — must throw.
        };

        $locker = new CacheLocker($nonAtomicCache);

        // This must throw because non-atomic has()+set() would allow TOCTOU
        expect(fn () => $locker->acquire('resource:1'))
            ->toThrow(\BAGArt\AsyncKernel\Exceptions\ASKTechnicalException::class);
    });

    it('multiple locks on different keys are independent', function () {
        $store = [];
        $cache = makeAtomicCache($store);

        $locker = new CacheLocker($cache);

        expect($locker->acquire('key:1'))->toBeTrue();
        expect($locker->acquire('key:2'))->toBeTrue();
        expect($locker->acquire('key:1'))->toBeFalse(); // Already held
    });
});
