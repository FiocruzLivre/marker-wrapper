<?php

declare(strict_types=1);

namespace FiocruzLivre\MarkerWrapper;

use InvalidArgumentException;

final readonly class CachePolicy
{
    public function __construct(
        public int $tileTtl = 604800,
        public int $staticTtl = 86400,
        public int $markerTtl = 2592000,
    ) {
        foreach ([$tileTtl, $staticTtl, $markerTtl] as $ttl) {
            if ($ttl < 60 || $ttl > 31536000) {
                throw new InvalidArgumentException('Cache lifetime must be between 60 seconds and 365 days');
            }
        }
    }

    public static function fromEnvironment(): self
    {
        return new self(
            self::ttlFromEnv('TILE_CACHE_TTL', 604800),
            self::ttlFromEnv('STATIC_CACHE_TTL', 86400),
            self::ttlFromEnv('MARKER_CACHE_TTL', 2592000),
        );
    }

    private static function ttlFromEnv(string $name, int $default): int
    {
        $raw = getenv($name);
        if ($raw === false || $raw === '') {
            return $default;
        }
        $ttl = filter_var(
            $raw,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 60, 'max_range' => 31536000]],
        );
        if ($ttl === false) {
            throw new InvalidArgumentException("Invalid TTL: $name");
        }
        return $ttl;
    }
}
