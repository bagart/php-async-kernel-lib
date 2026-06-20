<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Contracts;

/**
 * Locker contract (distributed/local locking).
 *
 * Extended in Phase 0 (outbound pipeline) with methods accepting explicit TTL and owner —
 * needed for ordering lock (todo.md §0.2, §3.5): TTL guarantees auto-release on worker crash,
 * owner ensures safe release (only the owner releases the lock).
 *
 * Existing methods {@see acquire()} / {@see release()} remain unchanged
 * (todo.md §0.1: extend by adding only, do not modify existing).
 */
interface ASKLockerContract
{
    /**
     * Acquire lock (without explicit TTL — implementation uses its own default).
     *
     * @param string $key Lock key.
     *
     * @return bool true — acquired; false — already held.
     */
    public function acquire(string $key): bool;

    /**
     * Release lock (without owner check — unconditional release).
     *
     * @param string $key Lock key.
     */
    public function release(string $key): void;

    /**
     * Acquire lock with explicit TTL and optional owner.
     *
     * TTL guarantees auto-release: on worker crash the lock expires after $ttl
     * seconds, not remaining held forever. Owner enables safe release —
     * only the owner (or anyone when owner=null) can release the lock.
     *
     * @param string      $key   Lock key.
     * @param int         $ttl   TTL in seconds (after which the lock automatically releases).
     * @param string|null $owner Owner identifier; null — generates a random token
     *                           (like in acquire()), but then releaseWithOwner without an owner
     *                           won't work correctly for this call. Pass an explicit owner
     *                           for lifetime management.
     *
     * @return bool true — acquired; false — already held (or TTL not yet expired).
     */
    public function acquireWithTtl(string $key, int $ttl, ?string $owner = null): bool;

    /**
     * Release lock with owner check.
     *
     * If $owner is specified — releases ONLY when the owner matches (safe release:
     * a different worker cannot release another's lock). If $owner = null — unconditional
     * release (like release(), for back-compat and cases where owner is not important).
     *
     * @param string      $key   Lock key.
     * @param string|null $owner Owner identifier; null — unconditional release.
     */
    public function releaseWithOwner(string $key, ?string $owner = null): void;
}
