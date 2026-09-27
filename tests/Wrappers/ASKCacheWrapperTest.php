<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\Exceptions\ASKTechnicalException;
use BAGArt\AsyncKernel\Wrappers\ASKCacheWrapper;
use Psr\SimpleCache\CacheInterface;

require_once __DIR__.'/Fixtures/IlluminateStoreStub.php';

/**
 * PSR-16 fake extended with the Laravel Store methods ASKCacheWrapper
 * delegates to. Deliberately has NO add() — see ASKCacheWrapperAtomicBackendFake.
 */
class ASKCacheWrapperBackendFake implements CacheInterface
{
    /** @var array<string, int|null> */
    public array $ttls = [];

    public int $setCalls = 0;

    public string $prefix = 'ask_test_';

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
        $this->ttls[$key] = is_int($ttl) ? $ttl : null;
        $this->setCalls++;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key], $this->ttls[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->store = [];
        $this->ttls = [];

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

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->delete($key);

        return $value;
    }

    public function forever($key, mixed $value): bool
    {
        $this->store[$key] = $value;
        $this->ttls[$key] = null;
        $this->setCalls++;

        return true;
    }

    public function increment($key, mixed $value = 1): int|bool
    {
        $next = (int) ($this->store[$key] ?? 0) + (int) $value;
        $this->store[$key] = $next;
        $this->setCalls++;

        return $next;
    }

    public function decrement($key, mixed $value = 1): int|bool
    {
        return $this->increment($key, -(int) $value);
    }

    public function remember(string $key, mixed $ttl, \Closure $callback): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }

        $value = $callback();
        $this->set($key, $value, is_int($ttl) ? $ttl : null);

        return $value;
    }

    public function rememberForever(string $key, \Closure $callback): mixed
    {
        return $this->remember($key, null, $callback);
    }

    public function sear(string $key, \Closure $callback): mixed
    {
        return $this->remember($key, null, $callback);
    }

    public function many(array $keys)
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }

        return $result;
    }

    public function putMany(array $values, $seconds)
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $seconds);
        }

        return true;
    }

    public function flush()
    {
        $this->clear();

        return true;
    }

    public function getPrefix()
    {
        return $this->prefix;
    }

    public function getStore(): \Illuminate\Contracts\Cache\Store
    {
        return new class implements \Illuminate\Contracts\Cache\Store {};
    }
}

/**
 * Same backend plus atomic add() — the path ASKCacheWrapper::add() requires.
 */
final class ASKCacheWrapperAtomicBackendFake extends ASKCacheWrapperBackendFake
{
    public int $addCalls = 0;

    public function add(string $key, mixed $value, \DateTimeInterface|\DateInterval|int|null $ttl = null): bool
    {
        $this->addCalls++;

        if ($this->has($key)) {
            return false;
        }

        return $this->set($key, $value, $ttl);
    }
}

describe('ASKCacheWrapper PSR-16 surface', function () {
    it('get returns a stored value', function () {
        $store = ['key' => 'value'];
        $wrapper = new ASKCacheWrapper(new ASKCacheWrapperBackendFake($store));

        expect($wrapper->get('key'))->toBe('value');
    });

    it('get returns the default for a missing key', function () {
        $store = [];
        $wrapper = new ASKCacheWrapper(new ASKCacheWrapperBackendFake($store));

        expect($wrapper->get('missing', 'fallback'))->toBe('fallback');
        expect($wrapper->get('missing'))->toBeNull();
    });

    it('get returns a stored null instead of the default', function () {
        $store = ['key' => null];
        $wrapper = new ASKCacheWrapper(new ASKCacheWrapperBackendFake($store));

        expect($wrapper->get('key', 'fallback'))->toBeNull();
    });

    it('set stores the value and returns true', function () {
        $store = [];
        $wrapper = new ASKCacheWrapper(new ASKCacheWrapperBackendFake($store));

        expect($wrapper->set('key', ['a' => 1]))->toBeTrue();
        expect($store['key'])->toBe(['a' => 1]);
    });

    it('set records a null TTL when none is given', function () {
        $store = [];
        $backend = new ASKCacheWrapperBackendFake($store);
        $wrapper = new ASKCacheWrapper($backend);

        $wrapper->set('key', 'value');

        expect($backend->ttls['key'])->toBeNull();
    });

    it('has reflects key existence', function () {
        $store = ['present' => 1];
        $wrapper = new ASKCacheWrapper(new ASKCacheWrapperBackendFake($store));

        expect($wrapper->has('present'))->toBeTrue();
        expect($wrapper->has('absent'))->toBeFalse();
    });

    it('delete removes the key', function () {
        $store = ['key' => 'value'];
        $wrapper = new ASKCacheWrapper(new ASKCacheWrapperBackendFake($store));

        expect($wrapper->delete('key'))->toBeTrue();
        expect($store)->toBe([]);
        expect($wrapper->has('key'))->toBeFalse();
    });

    it('forget delegates to delete', function () {
        $store = ['key' => 'value'];
        $wrapper = new ASKCacheWrapper(new ASKCacheWrapperBackendFake($store));

        expect($wrapper->forget('key'))->toBeTrue();
        expect($store)->toBe([]);
    });

    it('clear empties the backend', function () {
        $store = ['a' => 1, 'b' => 2];
        $wrapper = new ASKCacheWrapper(new ASKCacheWrapperBackendFake($store));

        expect($wrapper->clear())->toBeTrue();
        expect($store)->toBe([]);
    });

    it('setMultiple stores every entry', function () {
        $store = [];
        $backend = new ASKCacheWrapperBackendFake($store);
        $wrapper = new ASKCacheWrapper($backend);

        expect($wrapper->setMultiple(['a' => 1, 'b' => 2], 30))->toBeTrue();
        expect($store)->toBe(['a' => 1, 'b' => 2]);
        expect($backend->ttls)->toBe(['a' => 30, 'b' => 30]);
    });

    it('getMultiple returns values and defaults for missing keys', function () {
        $store = ['a' => 1];
        $wrapper = new ASKCacheWrapper(new ASKCacheWrapperBackendFake($store));

        expect($wrapper->getMultiple(['a', 'b'], 'fallback'))
            ->toBe(['a' => 1, 'b' => 'fallback']);
    });

    it('deleteMultiple removes every requested key', function () {
        $store = ['a' => 1, 'b' => 2, 'c' => 3];
        $wrapper = new ASKCacheWrapper(new ASKCacheWrapperBackendFake($store));

        expect($wrapper->deleteMultiple(['a', 'c']))->toBeTrue();
        expect($store)->toBe(['b' => 2]);
    });
});

describe('ASKCacheWrapper Laravel Store surface', function () {
    it('put forwards seconds to the backend as TTL', function () {
        $store = [];
        $backend = new ASKCacheWrapperBackendFake($store);
        $wrapper = new ASKCacheWrapper($backend);

        expect($wrapper->put('key', 'value', 90))->toBeTrue();
        expect($store['key'])->toBe('value');
        expect($backend->ttls['key'])->toBe(90);
    });

    it('pull returns the value and deletes the key', function () {
        $store = ['key' => 'value'];
        $wrapper = new ASKCacheWrapper(new ASKCacheWrapperBackendFake($store));

        expect($wrapper->pull('key', 'fallback'))->toBe('value');
        expect($store)->toBe([]);
        expect($wrapper->pull('key', 'fallback'))->toBe('fallback');
    });

    it('forever stores the value without a TTL', function () {
        $store = [];
        $backend = new ASKCacheWrapperBackendFake($store);
        $wrapper = new ASKCacheWrapper($backend);

        expect($wrapper->forever('key', 'value'))->toBeTrue();
        expect($store['key'])->toBe('value');
        expect($backend->ttls['key'])->toBeNull();
    });

    it('increment and decrement adjust the stored counter', function () {
        $store = ['counter' => 10];
        $wrapper = new ASKCacheWrapper(new ASKCacheWrapperBackendFake($store));

        expect($wrapper->increment('counter', 3))->toBe(13);
        expect($wrapper->decrement('counter', 5))->toBe(8);
        expect($store['counter'])->toBe(8);
    });

    it('many returns the requested values and null for missing keys', function () {
        $store = ['a' => 'x'];
        $wrapper = new ASKCacheWrapper(new ASKCacheWrapperBackendFake($store));

        expect($wrapper->many(['a', 'b']))->toBe(['a' => 'x', 'b' => null]);
    });

    it('putMany stores every entry with the given TTL', function () {
        $store = [];
        $backend = new ASKCacheWrapperBackendFake($store);
        $wrapper = new ASKCacheWrapper($backend);

        expect($wrapper->putMany(['a' => 1, 'b' => 2], 90))->toBeTrue();
        expect($store)->toBe(['a' => 1, 'b' => 2]);
        expect($backend->ttls)->toBe(['a' => 90, 'b' => 90]);
    });

    it('flush clears the backend', function () {
        $store = ['a' => 1];
        $wrapper = new ASKCacheWrapper(new ASKCacheWrapperBackendFake($store));

        expect($wrapper->flush())->toBeTrue();
        expect($store)->toBe([]);
    });

    it('getPrefix returns the backend prefix', function () {
        $store = [];
        $wrapper = new ASKCacheWrapper(new ASKCacheWrapperBackendFake($store));

        expect($wrapper->getPrefix())->toBe('ask_test_');
    });

    it('remember runs the callback once and reuses the cached value', function () {
        $store = [];
        $backend = new ASKCacheWrapperBackendFake($store);
        $wrapper = new ASKCacheWrapper($backend);

        $calls = 0;
        $callback = function () use (&$calls): string {
            $calls++;

            return 'computed';
        };

        expect($wrapper->remember('key', 60, $callback))->toBe('computed');
        expect($wrapper->remember('key', 60, $callback))->toBe('computed');
        expect($calls)->toBe(1);
        expect($backend->ttls['key'])->toBe(60);
    });

    it('rememberForever caches the callback result without a TTL', function () {
        $store = [];
        $backend = new ASKCacheWrapperBackendFake($store);
        $wrapper = new ASKCacheWrapper($backend);

        $calls = 0;
        $callback = function () use (&$calls): string {
            $calls++;

            return 'computed';
        };

        expect($wrapper->rememberForever('key', $callback))->toBe('computed');
        expect($wrapper->rememberForever('key', $callback))->toBe('computed');
        expect($calls)->toBe(1);
        expect($backend->ttls['key'])->toBeNull();
    });

    it('sear runs the callback once and reuses the cached value', function () {
        $store = [];
        $wrapper = new ASKCacheWrapper(new ASKCacheWrapperBackendFake($store));

        $calls = 0;
        $callback = function () use (&$calls): string {
            $calls++;

            return 'computed';
        };

        expect($wrapper->sear('key', $callback))->toBe('computed');
        expect($wrapper->sear('key', $callback))->toBe('computed');
        expect($calls)->toBe(1);
    });

    it('getStore returns the underlying store', function () {
        $store = [];
        $wrapper = new ASKCacheWrapper(new ASKCacheWrapperBackendFake($store));

        expect($wrapper->getStore())->toBeInstanceOf(\Illuminate\Contracts\Cache\Store::class);
    });
});

describe('ASKCacheWrapper::add() contract (H3)', function () {
    it('supportsAtomic is true only when the backend implements add()', function () {
        $store = [];
        $atomic = new ASKCacheWrapper(new ASKCacheWrapperAtomicBackendFake($store));
        $plain = new ASKCacheWrapper(new ASKCacheWrapperBackendFake($store));

        expect($atomic->supportsAtomic())->toBeTrue();
        expect($plain->supportsAtomic())->toBeFalse();
    });

    it('add creates the key once and refuses a duplicate', function () {
        $store = [];
        $backend = new ASKCacheWrapperAtomicBackendFake($store);
        $wrapper = new ASKCacheWrapper($backend);

        expect($wrapper->add('key', 'value', 60))->toBeTrue();
        expect($wrapper->add('key', 'other', 60))->toBeFalse();
        expect($store['key'])->toBe('value');
        expect($backend->addCalls)->toBe(2);
        expect($backend->ttls['key'])->toBe(60);
    });

    it('add throws ASKTechnicalException and writes nothing without backend support', function () {
        $store = ['held' => 'value'];
        $backend = new ASKCacheWrapperBackendFake($store);
        $wrapper = new ASKCacheWrapper($backend);

        expect(fn () => $wrapper->add('key', 'value', 60))
            ->toThrow(ASKTechnicalException::class);
        expect($store)->toBe(['held' => 'value']);
        expect($backend->setCalls)->toBe(0);
    });
});
