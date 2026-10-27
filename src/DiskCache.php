<?php

declare(strict_types=1);

namespace FiocruzLivre\MarkerWrapper;

final readonly class DiskCache
{
    public function __construct(private string $directory)
    {
    }

    public function read(string $key, int $maxAgeSeconds): ?string
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }
        $modifiedAt = @filemtime($path);
        if ($modifiedAt === false || time() - $modifiedAt >= $maxAgeSeconds) {
            return null;
        }
        $value = @file_get_contents($path);
        return $value === false ? null : $value;
    }

    public function write(string $key, string $value): void
    {
        $path = $this->path($key);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            error_log('MarkerWrapper: cache directory unavailable');
            return;
        }
        $temporary = @tempnam($dir, 'tile-');
        if ($temporary === false) {
            error_log('MarkerWrapper: cache temporary file unavailable');
            return;
        }
        try {
            if (@file_put_contents($temporary, $value, LOCK_EX) === false ||
                !@rename($temporary, $path)) {
                error_log('MarkerWrapper: cache write failed');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * Maintenance-only cleanup, never invoked while serving HTTP requests.
     */
    public function pruneExpired(int $retentionSeconds): int
    {
        if ($retentionSeconds < 60) {
            throw new \InvalidArgumentException('Invalid cache retention');
        }

        if (!is_dir($this->directory)) {
            return 0;
        }

        $deleted = 0;
        $expiry = time() - $retentionSeconds;
        foreach (new \DirectoryIterator($this->directory) as $prefix) {
            if ($prefix->isDot() || $prefix->isLink() || !$prefix->isDir()) {
                continue;
            }

            foreach (new \DirectoryIterator($prefix->getPathname()) as $entry) {
                if ($entry->isFile()
                    && !$entry->isLink()
                    && $entry->getExtension() === 'bin'
                    && $entry->getMTime() <= $expiry
                    && @unlink($entry->getPathname())
                ) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    private function path(string $key): string
    {
        $hash = hash('sha256', $key);
        return rtrim($this->directory, '/') . '/' . substr($hash, 0, 2) . '/' . $hash . '.bin';
    }
}
