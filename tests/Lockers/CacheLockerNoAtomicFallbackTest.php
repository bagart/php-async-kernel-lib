<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\Exceptions\ASKTechnicalException;
use BAGArt\AsyncKernel\Lockers\CacheLocker;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 fake WITHOUT add() that counts writes, so a silent has()+set()
 * fallback would be observable as a non-zero counter.
 */
function makeNoAtomicAddCache(array &$store): CacheInterface
{
    return new class ($store) implements CacheInterface {
        public int $setCalls = 0;

        /** @param  array<string, mixed>  $store */
        public function __construct(
            private array &$store,
        ) {
        }

        public function get(string $key, mixed $default = null): mixed
        {
            return array_key_exists($key, $this->store) ? $this->store[$key] : $default;
        }

        public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
        {
            $this->store[$key] = $value;
            $this->setCalls++;

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
            $result = [];
            foreach ($keys as $key) {
                $result[$key] = $this->get($key, $default);
            }

            return $result;
        }

        public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
        {
            foreach ($values as $key => $value) {
                $this->set((string) $key, $value, $ttl);
            }

            return true;
        }

        public function deleteMultiple(iterable $keys): bool
        {
            foreach ($keys as $key) {
                $this->delete($key);
            }

            return true;
        }

        public function has(string $key): bool
        {
            return array_key_exists($key, $this->store);
        }

        // Deliberately no add() — CacheLocker must refuse, never race.
    };
}

describe('CacheLocker non-atomic backend refusal (H1)', function () {
    it('throws from acquireWithTtl without writing anything', function () {
        $store = [];
        $cache = makeNoAtomicAddCache($store);
        $locker = new CacheLocker($cache);

        expect(fn () => $locker->acquireWithTtl('chat:1', 60, 'owner-A'))
            ->toThrow(ASKTechnicalException::class, 'atomic add()');
        expect($store)->toBe([]);
        expect($cache->setCalls)->toBe(0);
    });

    it('throws from acquire() with the default TTL and owner', function () {
        $store = [];
        $cache = makeNoAtomicAddCache($store);
        $locker = new CacheLocker($cache);

        expect(fn () => $locker->acquire('chat:1'))->toThrow(ASKTechnicalException::class);
        expect($store)->toBe([]);
        expect($cache->setCalls)->toBe(0);
    });

    it('throws even when the lock key is already held — no has()-based branching', function () {
        $store = ['ask_lock_chat:1' => 'other-owner'];
        $cache = makeNoAtomicAddCache($store);
        $locker = new CacheLocker($cache);

        expect(fn () => $locker->acquireWithTtl('chat:1', 60, 'owner-A'))
            ->toThrow(ASKTechnicalException::class);
        expect($store)->toBe(['ask_lock_chat:1' => 'other-owner']);
        expect($cache->setCalls)->toBe(0);
    });

    it('routes through add() when the backend supports it', function () {
        $store = [];
        $cache = new class ($store) implements CacheInterface {
            public int $addCalls = 0;

            /** @param  array<string, mixed>  $store */
            public function __construct(
                private array &$store,
            ) {
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return array_key_exists($key, $this->store) ? $this->store[$key] : $default;
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

            public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
            {
                $this->addCalls++;

                if ($this->has($key)) {
                    return false;
                }

                return $this->set($key, $value, $ttl);
            }
        };

        $lockerA = new CacheLocker($cache);
        $lockerB = new CacheLocker($cache);

        expect($lockerA->acquire('chat:1'))->toBeTrue();
        expect($lockerB->acquire('chat:1'))->toBeFalse();
        expect($cache->addCalls)->toBe(2);

        $token = (new ReflectionProperty($lockerA, 'token'))->getValue($lockerA);
        expect($store)->toBe(['ask_lock_chat:1' => $token]);
    });
});
