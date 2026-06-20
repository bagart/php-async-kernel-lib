<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Lockers;

use BAGArt\AsyncKernel\Contracts\ASKLockerContract;
use Psr\SimpleCache\CacheInterface;

/**
 * Locker based on PSR-16 CacheInterface.
 *
 * Uses atomic add() (if the backend supports it) for set-if-not-exists,
 * otherwise falls back to has()+set(). Owner token is stored as the key value —
 * release checks the owner before deletion.
 *
 * New methods {@see acquireWithTtl()}/{@see releaseWithOwner()} allow explicit TTL
 * and owner (for ordering lock, todo.md §3.5). Existing acquire()/release()
 * delegate to them with defaults (TTL=30s, owner=$this->token).
 */
final class CacheLocker implements ASKLockerContract
{
    private const string KEY_PREFIX = 'ask_lock_';

    private const int LOCK_TTL_SECONDS = 30;

    private readonly string $token;

    public function __construct(
        private readonly CacheInterface $cache,
    ) {
        $this->token = bin2hex(random_bytes(16));
    }

    public function acquire(string $key): bool
    {
        return $this->acquireWithTtl($key, self::LOCK_TTL_SECONDS, $this->token);
    }

    public function release(string $key): void
    {
        $this->releaseWithOwner($key, $this->token);
    }

    public function acquireWithTtl(string $key, int $ttl, ?string $owner = null): bool
    {
        $cacheKey = self::KEY_PREFIX.$key;
        $ownerToken = $owner ?? $this->token;

        // Atomic add() — preferred (set-if-not-exists in a single call).
        if (method_exists($this->cache, 'add')) {
            return (bool)$this->cache->add(
                $cacheKey,
                $ownerToken,
                $ttl,
            );
        }

        // Fallback: has()+set() — NOT atomic (race window), but works with any PSR-16.
        if ($this->cache->has($cacheKey)) {
            return false;
        }

        return (bool)$this->cache->set(
            $cacheKey,
            $ownerToken,
            $ttl,
        );
    }

    public function releaseWithOwner(string $key, ?string $owner = null): void
    {
        $cacheKey = self::KEY_PREFIX.$key;

        // owner = null → unconditional release (back-compat, but safer with owner).
        if ($owner === null) {
            $this->cache->delete($cacheKey);

            return;
        }

        // owner specified — delete only if owner matches.
        $currentOwner = $this->cache->get($cacheKey);

        if ($currentOwner === $owner) {
            $this->cache->delete($cacheKey);
        }
    }
}
