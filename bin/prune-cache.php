<?php

declare(strict_types=1);

use FiocruzLivre\MarkerWrapper\CachePolicy;
use FiocruzLivre\MarkerWrapper\DiskCache;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Cache maintenance requires CLI execution');
}

$policy = CachePolicy::fromEnvironment();
$cacheDir = getenv('CACHE_DIR');
$cache = new DiskCache(
    $cacheDir === false || $cacheDir === '' ? dirname(__DIR__) . '/cache' : $cacheDir,
);
$retention = max($policy->tileTtl, $policy->staticTtl, $policy->markerTtl);
printf("Pruned %d expired entries\n", $cache->pruneExpired($retention));
