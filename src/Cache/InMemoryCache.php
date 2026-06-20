<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Cache;

use BAGArt\AsyncKernel\Contracts\ASKClockContract;
use BAGArt\AsyncKernel\Contracts\Cache\ASKCacheContract;
use DateInterval;

final class InMemoryCache implements ASKCacheContract
{
    use ASKCacheSimpleReuseMethodsTrait;
    public const string TYPE = 'in_memory';

    /** @var array<string, array{value: mixed, expires_at: ?float}> */
    private array $store = [];

    public function __construct(
        private readonly ASKClockContract $clock
    ) {
    }

    public static function build(
        ASKClockContract $clock,
    ): self {
        return new self($clock);
    }

    protected function clock(): ASKClockContract
    {
        return $this->clock;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!isset($this->store[$key])) {
            return $default;
        }

        $entry = $this->store[$key];

        if ($entry['expires_at'] !== null && $this->clock->microtime() > $entry['expires_at']) {
            unset($this->store[$key]);
            return $default;
        }

        return $entry['value'];
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $this->store[$key] = [
            'value' => $value,
            'expires_at' => $this->resolveExpiry($ttl),
        ];

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
            $result[$key] = $this->get((string)$key, $default);
        }
        return $result;
    }

    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string)$key, $value, $ttl);
        }
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete((string)$key);
        }
        return true;
    }

    public function has(string $key): bool
    {
        if (!isset($this->store[$key])) {
            return false;
        }

        $entry = $this->store[$key];

        if ($entry['expires_at'] !== null && $this->clock->microtime() > $entry['expires_at']) {
            unset($this->store[$key]);
            return false;
        }

        return true;
    }

    private function resolveExpiry(DateInterval|int|null $ttl): ?float
    {
        if ($ttl === null) {
            return null;
        }

        $seconds = ($ttl instanceof DateInterval)
            ? $this->clock->getSecondsFromInterval($ttl)
            : $ttl;

        return $seconds > 0 ? $this->clock->microtime() + $seconds : null;
    }
}
