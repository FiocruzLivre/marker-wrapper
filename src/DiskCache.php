<?php
declare(strict_types=1);

namespace FiocruzLivre\MarkerWrapper;

final readonly class DiskCache
{
    public function __construct(private string $directory) {}

    public function read(string $key, int $maxAgeSeconds): ?string
    {
        $path = $this->path($key);
        if (!is_file($path) || time() - (filemtime($path) ?: 0) >= $maxAgeSeconds) {
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

    private function path(string $key): string
    {
        $hash = hash('sha256', $key);
        return rtrim($this->directory, '/') . '/' . substr($hash, 0, 2) . '/' . $hash . '.bin';
    }
}
