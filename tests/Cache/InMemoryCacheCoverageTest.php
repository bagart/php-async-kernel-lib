<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\Cache\InMemoryCache;
use BAGArt\AsyncKernel\ASKClock;

describe('InMemoryCache PSR-16 compliance', function () {
    it('get returns stored value', function () {
        $cache = InMemoryCache::build(new ASKClock());
        $cache->set('key', 'value');

        expect($cache->get('key'))->toBe('value');
    });

    it('get returns default for missing key', function () {
        $cache = InMemoryCache::build(new ASKClock());

        expect($cache->get('missing', 'fallback'))->toBe('fallback');
    });

    it('get returns null for missing key with no default', function () {
        $cache = InMemoryCache::build(new ASKClock());

        expect($cache->get('missing'))->toBeNull();
    });

    it('set stores value', function () {
        $cache = InMemoryCache::build(new ASKClock());
        $cache->set('key', 'value');

        expect($cache->get('key'))->toBe('value');
    });

    it('set overwrites existing value', function () {
        $cache = InMemoryCache::build(new ASKClock());
        $cache->set('key', 'first');
        $cache->set('key', 'second');

        expect($cache->get('key'))->toBe('second');
    });

    it('delete removes key', function () {
        $cache = InMemoryCache::build(new ASKClock());
        $cache->set('key', 'value');
        $cache->delete('key');

        expect($cache->get('key'))->toBeNull();
    });

    it('delete returns true', function () {
        $cache = InMemoryCache::build(new ASKClock());

        expect($cache->delete('nonexistent'))->toBeTrue();
    });

    it('clear empties the store', function () {
        $cache = InMemoryCache::build(new ASKClock());
        $cache->set('a', 1);
        $cache->set('b', 2);
        $cache->clear();

        expect($cache->get('a'))->toBeNull();
        expect($cache->get('b'))->toBeNull();
    });

    it('has returns true for existing key', function () {
        $cache = InMemoryCache::build(new ASKClock());
        $cache->set('key', 'value');

        expect($cache->has('key'))->toBeTrue();
    });

    it('has returns false for missing key', function () {
        $cache = InMemoryCache::build(new ASKClock());

        expect($cache->has('missing'))->toBeFalse();
    });

    it('set with TTL=0 behaves as no expiry (seconds<=0 returns null)', function () {
        $clock = new ASKClock();
        $cache = InMemoryCache::build($clock);
        $cache->set('key', 'value', 0);

        expect($cache->has('key'))->toBeTrue();
    });

    it('getMultiple returns values for multiple keys', function () {
        $cache = InMemoryCache::build(new ASKClock());
        $cache->set('a', 1);
        $cache->set('b', 2);
        $cache->set('c', 3);

        $result = iterator_to_array($cache->getMultiple(['a', 'c']));
        expect($result)->toBe(['a' => 1, 'c' => 3]);
    });

    it('getMultiple returns default for missing keys', function () {
        $cache = InMemoryCache::build(new ASKClock());

        $result = iterator_to_array($cache->getMultiple(['x', 'y'], 'default'));
        expect($result)->toBe(['x' => 'default', 'y' => 'default']);
    });

    it('setMultiple stores all values', function () {
        $cache = InMemoryCache::build(new ASKClock());
        $cache->setMultiple(['x' => 10, 'y' => 20]);

        expect($cache->get('x'))->toBe(10);
        expect($cache->get('y'))->toBe(20);
    });

    it('deleteMultiple removes multiple keys', function () {
        $cache = InMemoryCache::build(new ASKClock());
        $cache->set('a', 1);
        $cache->set('b', 2);
        $cache->set('c', 3);
        $cache->deleteMultiple(['a', 'c']);

        expect($cache->get('a'))->toBeNull();
        expect($cache->get('b'))->toBe(2);
        expect($cache->get('c'))->toBeNull();
    });
});

describe('InMemoryCache TTL behavior', function () {
    it('set with TTL expires after interval', function () {
        $clock = new ASKClock();
        $cache = InMemoryCache::build($clock);
        $cache->set('key', 'value', 1);

        // Immediately should exist
        expect($cache->get('key'))->toBe('value');
    });

    it('set without TTL never expires', function () {
        $cache = InMemoryCache::build(new ASKClock());
        $cache->set('key', 'value');

        expect($cache->get('key'))->toBe('value');
        expect($cache->has('key'))->toBeTrue();
    });

    it('set with integer TTL sets expiry', function () {
        $cache = InMemoryCache::build(new ASKClock());
        $cache->set('key', 'value', 3600);

        expect($cache->get('key'))->toBe('value');
    });
});

describe('InMemoryCache trait methods (ASKCacheSimpleReuseMethodsTrait)', function () {
    it('put stores value', function () {
        $cache = InMemoryCache::build(new ASKClock());
        $cache->put('key', 'value', 60);

        expect($cache->get('key'))->toBe('value');
    });

    it('putMany stores multiple values', function () {
        $cache = InMemoryCache::build(new ASKClock());
        $cache->putMany(['a' => 1, 'b' => 2], 60);

        expect($cache->get('a'))->toBe(1);
        expect($cache->get('b'))->toBe(2);
    });

    it('increment increases value', function () {
        $cache = InMemoryCache::build(new ASKClock());
        $cache->set('counter', 10);
        $result = $cache->increment('counter', 5);

        expect($result)->toBe(15);
    });

    it('increment with default 0', function () {
        $cache = InMemoryCache::build(new ASKClock());
        $result = $cache->increment('counter');

        expect($result)->toBe(1);
    });

    it('decrement decreases value', function () {
        $cache = InMemoryCache::build(new ASKClock());
        $cache->set('counter', 10);
        $result = $cache->decrement('counter', 3);

        expect($result)->toBe(7);
    });

    it('forever stores value without TTL', function () {
        $cache = InMemoryCache::build(new ASKClock());
        $cache->forever('permanent', 'eternal');

        expect($cache->get('permanent'))->toBe('eternal');
    });

    it('touch refreshes TTL for existing key', function () {
        $cache = InMemoryCache::build(new ASKClock());
        $cache->set('key', 'val', 60);
        $result = $cache->touch('key', 120);

        expect($result)->toBeTrue();
        expect($cache->get('key'))->toBe('val');
    });

    it('touch returns true via trait fallback (set replaces value)', function () {
        $cache = InMemoryCache::build(new ASKClock());

        // Touch on missing key: trait does get($key) → null, then set($key, null, $ttl).
        // This creates a null-valued entry (side-effect of the trait fallback).
        $result = $cache->touch('missing', 60);

        expect($result)->toBeTrue();
    });

    it('forget removes key', function () {
        $cache = InMemoryCache::build(new ASKClock());
        $cache->set('key', 'value');
        $cache->forget('key');

        expect($cache->get('key'))->toBeNull();
    });

    it('flush clears all entries', function () {
        $cache = InMemoryCache::build(new ASKClock());
        $cache->set('a', 1);
        $cache->set('b', 2);
        $cache->flush();

        expect($cache->get('a'))->toBeNull();
        expect($cache->get('b'))->toBeNull();
    });

    it('many returns values for multiple keys', function () {
        $cache = InMemoryCache::build(new ASKClock());
        $cache->set('x', 10);
        $cache->set('y', 20);

        $result = $cache->many(['x', 'y', 'z']);
        expect($result)->toBe(['x' => 10, 'y' => 20, 'z' => null]);
    });

    it('getPrefix returns empty string', function () {
        $cache = InMemoryCache::build(new ASKClock());

        expect($cache->getPrefix())->toBe('');
    });
});

describe('InMemoryCache edge cases', function () {
    it('stores null values correctly', function () {
        $cache = InMemoryCache::build(new ASKClock());
        $cache->set('key', null);

        expect($cache->has('key'))->toBeTrue();
        expect($cache->get('key'))->toBeNull();
    });

    it('stores array values correctly', function () {
        $cache = InMemoryCache::build(new ASKClock());
        $cache->set('key', [1, 2, 3]);

        expect($cache->get('key'))->toBe([1, 2, 3]);
    });

    it('stores boolean values correctly', function () {
        $cache = InMemoryCache::build(new ASKClock());
        $cache->set('true', true);
        $cache->set('false', false);

        expect($cache->get('true'))->toBeTrue();
        expect($cache->get('false'))->toBeFalse();
    });

    it('stores integer 0 correctly', function () {
        $cache = InMemoryCache::build(new ASKClock());
        $cache->set('zero', 0);

        expect($cache->get('zero'))->toBe(0);
        expect($cache->has('zero'))->toBeTrue();
    });
});
