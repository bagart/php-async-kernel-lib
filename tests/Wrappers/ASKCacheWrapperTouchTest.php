<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\Wrappers\ASKCacheWrapper;
use Psr\SimpleCache\CacheInterface;

/**
 * Hand-rolled PSR-16 fake with native touch() support.
 */
function makeCacheWithTouch(array &$store): CacheInterface
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

        public function touch(string $key, int $ttl): bool
        {
            if (!$this->has($key)) {
                return false;
            }

            // Native touch — just refresh TTL, value unchanged.
            return true;
        }
    };
}

/**
 * Hand-rolled PSR-16 fake WITHOUT native touch() — exercises the fallback path.
 */
function makeCacheWithoutTouch(array &$store): CacheInterface
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

        // Deliberately no touch() — forces fallback path.
    };
}

describe('ASKCacheWrapper::touch() (H2)', function () {
    it('delegates to native touch when available', function () {
        $store = ['existing' => 'value'];
        $cache = makeCacheWithTouch($store);
        $wrapper = new ASKCacheWrapper($cache);

        $result = $wrapper->touch('existing', 120);

        expect($result)->toBeTrue();
        // Value must remain unchanged.
        expect($store['existing'])->toBe('value');
    });

    it('returns false on native touch for missing key', function () {
        $store = [];
        $cache = makeCacheWithTouch($store);
        $wrapper = new ASKCacheWrapper($cache);

        expect($wrapper->touch('missing', 120))->toBeFalse();
    });

    it('fallback: refreshes TTL without corrupting value for existing scalar', function () {
        $store = ['key' => 'hello'];
        $cache = makeCacheWithoutTouch($store);
        $wrapper = new ASKCacheWrapper($cache);

        $result = $wrapper->touch('key', 60);

        expect($result)->toBeTrue();
        expect($store['key'])->toBe('hello');
    });

    it('fallback: refreshes TTL without corrupting value for existing array', function () {
        $store = ['key' => [1, 2, 3]];
        $cache = makeCacheWithoutTouch($store);
        $wrapper = new ASKCacheWrapper($cache);

        $result = $wrapper->touch('key', 60);

        expect($result)->toBeTrue();
        expect($store['key'])->toBe([1, 2, 3]);
    });

    it('fallback: preserves cached null without confusing with missing key', function () {
        $store = ['key' => null];
        $cache = makeCacheWithoutTouch($store);
        $wrapper = new ASKCacheWrapper($cache);

        $result = $wrapper->touch('key', 60);

        expect($result)->toBeTrue();
        // The value must remain null — not be replaced by TTL integer.
        expect(array_key_exists('key', $store))->toBeTrue();
        expect($store['key'])->toBeNull();
    });

    it('fallback: returns false for missing key', function () {
        $store = [];
        $cache = makeCacheWithoutTouch($store);
        $wrapper = new ASKCacheWrapper($cache);

        $result = $wrapper->touch('nonexistent', 60);

        expect($result)->toBeFalse();
        expect($store)->toBe([]);
    });

    it('fallback: never writes TTL integer as cache value', function () {
        $store = [];
        $cache = makeCacheWithoutTouch($store);
        $wrapper = new ASKCacheWrapper($cache);

        // Touch missing key — must not create an entry.
        $wrapper->touch('key', 300);

        expect($store)->toBe([]);
    });
});
