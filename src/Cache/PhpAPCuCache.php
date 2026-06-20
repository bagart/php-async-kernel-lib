<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Cache;

use BAGArt\AsyncKernel\Contracts\ASKClockContract;
use BAGArt\AsyncKernel\Contracts\Cache\ASKCacheContract;
use DateInterval;

final class PhpAPCuCache implements ASKCacheContract
{
    use ASKCacheSimpleReuseMethodsTrait;
    public const string TYPE = 'APCu';

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
        $value = apcu_fetch($key, $success);
        return $success ? $value : $default;
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        return apcu_store($key, $value, $this->resolveTtl($ttl));
    }

    public function delete(string $key): bool
    {
        return apcu_delete($key);
    }

    public function clear(): bool
    {
        return apcu_clear_cache();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keysArray = is_array($keys) ? $keys : iterator_to_array($keys);
        $values = apcu_fetch($keysArray);

        $result = [];
        foreach ($keysArray as $key) {
            $result[$key] = array_key_exists($key, $values) ? $values[$key] : $default;
        }
        return $result;
    }

    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        $data = is_array($values) ? $values : iterator_to_array($values);
        return empty(apcu_store($data, null, $this->resolveTtl($ttl)));
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $keysArray = is_array($keys) ? $keys : iterator_to_array($keys);
        return apcu_delete($keysArray) === [];
    }

    public function has(string $key): bool
    {
        return apcu_exists($key);
    }

    private function resolveTtl(DateInterval|int|null $ttl): int
    {
        if ($ttl === null) {
            return 0;
        }

        return ($ttl instanceof DateInterval)
            ? $this->clock->getSecondsFromInterval($ttl)
            : $ttl;
    }
}
