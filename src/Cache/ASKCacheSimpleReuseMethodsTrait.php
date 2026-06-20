<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Cache;

use BAGArt\AsyncKernel\Contracts\ASKClockContract;

/**
 * Provides default implementations for ASKCacheContract methods beyond PSR-16,
 * using primitive PSR-16 operations (get/set/delete/clear/getMultiple/setMultiple).
 *
 * Requires $this->clock (ASKClockContract) for TTL resolution.
 * Drivers with native batch/atomic operations should override the relevant methods.
 */
trait ASKCacheSimpleReuseMethodsTrait
{
    abstract protected function clock(): ASKClockContract;

    public function many(array $keys): array
    {
        return iterator_to_array($this->getMultiple($keys));
    }

    public function put($key, $value, $seconds): bool
    {
        return $this->set($key, $value, (int) $seconds);
    }

    public function putMany(array $values, $seconds): bool
    {
        return $this->setMultiple($values, (int) $seconds);
    }

    public function increment($key, $value = 1): int|bool
    {
        $current = $this->get($key, 0);
        $new = (int) $current + (int) $value;
        $this->set($key, $new);

        return $new;
    }

    public function decrement($key, $value = 1): int|bool
    {
        $current = $this->get($key, 0);
        $new = (int) $current - (int) $value;
        $this->set($key, $new);

        return $new;
    }

    public function forever($key, $value): bool
    {
        return $this->set($key, $value);
    }

    public function touch($key, $seconds): bool
    {
        return $this->set($key, $this->get($key), (int) $seconds);
    }

    public function forget($key): bool
    {
        return $this->delete($key);
    }

    public function flush(): bool
    {
        return $this->clear();
    }

    public function getPrefix(): string
    {
        return '';
    }
}
