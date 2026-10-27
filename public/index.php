<?php
declare(strict_types=1);

use FiocruzLivre\MarkerWrapper\DiskCache;
use FiocruzLivre\MarkerWrapper\Gateway;
use FiocruzLivre\MarkerWrapper\MarkerRenderer;
use FiocruzLivre\MarkerWrapper\StaticMapRenderer;
use GuzzleHttp\Client;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $application = new Gateway(
        new Client(),
        new DiskCache(getenv('CACHE_DIR') ?: dirname(__DIR__) . '/cache'),
        new MarkerRenderer(dirname(__DIR__) . '/icons/maki/icons'),
        new StaticMapRenderer(),
        getenv('TILE_SERVER') ?: 'https://tile.openstreetmap.org',
        getenv('MARKER_USER_AGENT') ?: 'MapaMoveSaude-MarkerWrapper/2.0 (+https://mapamovsaude.net.br/)',
        getenv('MARKER_REFERER') ?: 'https://mapamovsaude.net.br/',
    );
    $response = $application->handle($_SERVER['REQUEST_URI'] ?? '/');
    http_response_code($response->status);
    foreach ($response->headers as $key => $value) {
        header($key . ': ' . $value);
    }
    echo $response->body;
} catch (Throwable $e) {
    error_log('MarkerWrapper bootstrap failed: ' . get_class($e));
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "Internal error\n";
}
