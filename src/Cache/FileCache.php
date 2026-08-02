<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Cache;

use BAGArt\AsyncKernel\Contracts\ASKClockContract;
use BAGArt\AsyncKernel\Contracts\Cache\ASKCacheContract;
use DateInterval;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class FileCache implements ASKCacheContract
{
    use ASKCacheSimpleReuseMethodsTrait;

    public const string TYPE = 'file';
    private string $path;

    public function __construct(
        private readonly ASKClockContract $clock,
        string $cacheDir = 'storage/lib/cache'
    ) {
        $this->path = rtrim($cacheDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (!is_dir($this->path) && !mkdir($this->path, 0755, true) && !is_dir($this->path)) {
            throw new RuntimeException("Directory '{$this->path}' was not created");
        }
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
        $file = $this->getFilePath($key);
        if (!file_exists($file)) {
            return $default;
        }

        $content = file_get_contents($file);
        // Use [] instead of false for strict safety
        $data = $content !== false ? @unserialize($content, ['allowed_classes' => []]) : false;

        if ($data === false || ($data['expires'] !== null && $this->clock->microtime() > $data['expires'])) {
            $this->delete($key);
            return $default;
        }

        return $data['value'];
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $file = $this->getFilePath($key);
        $expires = $this->resolveExpiry($ttl);

        $data = serialize(['value' => $value, 'expires' => $expires]);

        return file_put_contents($file, $data) !== false;
    }

    public function has(string $key): bool
    {
        $file = $this->getFilePath($key);
        if (!file_exists($file)) {
            return false;
        }

        $content = file_get_contents($file);
        $data = $content !== false ? @unserialize($content, ['allowed_classes' => []]) : false;

        return $data !== false && ($data['expires'] === null || $this->clock->microtime() <= $data['expires']);
    }

    public function delete(string $key): bool
    {
        $file = $this->getFilePath($key);
        return file_exists($file) ? unlink($file) : true;
    }

    public function clear(): bool
    {
        if (!is_dir($this->path)) {
            return true;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get((string)$key, $default);
        }
        return $results;
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

    private function getFilePath(string $key): string
    {
        $hash = md5($key);
        $dir = $this->path.substr($hash, 0, 2).DIRECTORY_SEPARATOR.substr($hash, 2, 2);

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Directory '$dir' was not created");
        }

        return $dir.DIRECTORY_SEPARATOR.$hash;
    }

    private function resolveExpiry(DateInterval|int|null $ttl): ?float
    {
        $now = $this->clock->microtime();

        if ($ttl === null) {
            return null;
        }

        // Use clock math instead of heavy DateTime
        $seconds = ($ttl instanceof DateInterval)
            ? $this->clock->getSecondsFromInterval($ttl)
            : (int)$ttl;

        return $now + $seconds;
    }
}
