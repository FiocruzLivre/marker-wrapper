<?php

require '../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (!str_starts_with($requestPath, '/v4/marker')) {
    $tileServer = getenv('TILE_SERVER');

    $url = $tileServer . $_SERVER['REQUEST_URI'];

    try {
        $client = new Client();
        $response = $client->request('GET', $url);

        http_response_code($response->getStatusCode());
        foreach ($response->getHeaders() as $header => $values) {
            foreach ($values as $value) {
                header("$header: $value");
            }
        }
        echo $response->getBody();
    } catch (RequestException $e) {
        http_response_code(500);
    }
    return;
}

preg_match('/^\/v4\/marker\/(pin-(?<pin>[a-z]+))?-?(?<name>[a-z-]+)?\+?(?<color>[a-f0-9]{6})?\.png$/', $requestPath, $matches);
if (!$matches) {
    http_response_code(500);
    return;
}

try {
    $svgContent = file_get_contents('../icons/maki/icons/' . $matches['name'] . '.svg');
    $svgContent = preg_replace('/<path /', '<path fill="#' . $matches['color'] . '" ', $svgContent);
    $imagick = new Imagick();
    $imagick->readImageBlob($svgContent);
    $imagick->setImageFormat('png');

    header('Content-Type: image/png');
    echo $imagick->getImageBlob();

    $imagick->clear();
    $imagick->destroy();
} catch (\Exception $e) {
    http_response_code(500);
}
