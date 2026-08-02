<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\Lockers\CacheLocker;
use Psr\SimpleCache\CacheInterface;

/**
 * Hand-rolled in-memory PSR-16 fake for CacheLocker tests.
 * Does not use Mockery (project convention — hand-rolled fakes).
 * Supports add() for atomic set-if-not-exists.
 */
function makeArrayCache(): CacheInterface
{
    return new class () implements CacheInterface {
        /** @var array<string, mixed> */
        private array $store = [];

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

        /**
         * @param  list<string>  $keys
         */
        public function getMultiple(iterable $keys, mixed $default = null): iterable
        {
            $result = [];
            foreach ($keys as $key) {
                $result[$key] = $this->store[$key] ?? $default;
            }

            return $result;
        }

        public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
        {
            foreach ($values as $key => $value) {
                $this->store[$key] = $value;
            }

            return true;
        }

        public function deleteMultiple(iterable $keys): bool
        {
            foreach ($keys as $key) {
                unset($this->store[$key]);
            }

            return true;
        }

        public function has(string $key): bool
        {
            return array_key_exists($key, $this->store);
        }

        /**
         * Atomic set-if-not-exists (used by CacheLocker when available).
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

describe('CacheLocker', function () {
    it('acquires a free key', function () {
        $locker = new CacheLocker(makeArrayCache());

        expect($locker->acquire('chat:1'))->toBeTrue();
    });

    it('rejects a second acquire of an already-locked key', function () {
        $locker = new CacheLocker(makeArrayCache());

        $locker->acquire('chat:1');

        expect($locker->acquire('chat:1'))->toBeFalse();
    });

    it('allows re-acquire after release', function () {
        $locker = new CacheLocker(makeArrayCache());

        $locker->acquire('chat:1');
        $locker->release('chat:1');

        expect($locker->acquire('chat:1'))->toBeTrue();
    });
});

describe('CacheLocker::acquireWithTtl', function () {
    it('acquires with explicit owner', function () {
        $locker = new CacheLocker(makeArrayCache());

        expect($locker->acquireWithTtl('chat:1', 60, 'owner-A'))->toBeTrue();
    });

    it('rejects a second acquire regardless of owner', function () {
        $locker = new CacheLocker(makeArrayCache());

        $locker->acquireWithTtl('chat:1', 60, 'owner-A');

        expect($locker->acquireWithTtl('chat:1', 60, 'owner-B'))->toBeFalse();
    });
});

describe('CacheLocker::releaseWithOwner', function () {
    it('releases when owner matches', function () {
        $locker = new CacheLocker(makeArrayCache());

        $locker->acquireWithTtl('chat:1', 60, 'owner-A');
        $locker->releaseWithOwner('chat:1', 'owner-A');

        expect($locker->acquireWithTtl('chat:1', 60, 'owner-B'))->toBeTrue();
    });

    it('does NOT release when owner does not match (safe release)', function () {
        $locker = new CacheLocker(makeArrayCache());

        $locker->acquireWithTtl('chat:1', 60, 'owner-A');
        $locker->releaseWithOwner('chat:1', 'owner-B');

        expect($locker->acquireWithTtl('chat:1', 60, 'owner-C'))->toBeFalse();
    });

    it('release with owner=null releases unconditionally', function () {
        $locker = new CacheLocker(makeArrayCache());

        $locker->acquireWithTtl('chat:1', 60, 'owner-A');
        $locker->releaseWithOwner('chat:1', null);

        expect($locker->acquireWithTtl('chat:1', 60, 'owner-B'))->toBeTrue();
    });
});

describe('CacheLocker fallback path (no add() method)', function () {
    it('works with a cache that has no add() — uses has()+set() fallback', function () {
        // Cache without add() — testing the has()+set() fallback path.
        $cache = new class () implements CacheInterface {
            /** @var array<string, mixed> */
            private array $store = [];

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

            // Deliberately no add() — CacheLocker must use the fallback path.
        };

        $locker = new CacheLocker($cache);

        expect($locker->acquireWithTtl('chat:1', 60, 'owner-A'))->toBeTrue()
            ->and($locker->acquireWithTtl('chat:1', 60, 'owner-B'))->toBeFalse();
    });
});
