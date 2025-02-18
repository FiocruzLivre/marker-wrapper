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

preg_match('/^\/v4\/marker\/(pin-(?<pin>[a-z]+))?-?(?<name>[a-z-]+)?\+?(?<color>[a-f0-9]{3,6})?\.png$/', $requestPath, $matches);
if (!$matches) {
    http_response_code(500);
    return;
}

try {

    $background = 'marker';
    $backgroundStroked = $background . '-stroked';
    $marging = 6;
    $width = 35;
    $height = 45;

    $image = new Imagick();
    $image->newImage($width, $height * 2, new ImagickPixel('transparent'), 'png');
    $image->setImageFormat('png');

    $strokedColor ='111111';

    $backgroundStrokedContent = file_get_contents('../icons/maki/icons/' . $backgroundStroked . '.svg');
    $backgroundStrokedContent = preg_replace('/<path /', '<path fill="#' . $strokedColor . '" ', $backgroundStrokedContent);
    $backgroundStrokedSvg = new Imagick();
    $backgroundStrokedSvg->setBackgroundColor(new ImagickPixel('transparent'));
    $backgroundStrokedSvg->setResolution(300, 300);
    $backgroundStrokedSvg->readImageBlob($backgroundStrokedContent);
    $backgroundStrokedSvg->resizeImage($width + ($marging * 4), $height + ($marging * 4), Imagick::FILTER_LANCZOS, 1, true);
    $bgStrokedX = ($width - $backgroundStrokedSvg->getImageWidth()) / 2 - 1;
    $image->compositeImage($backgroundStrokedSvg, Imagick::COMPOSITE_OVER, $bgStrokedX, -4);

    $backgroundContent = file_get_contents('../icons/maki/icons/' . $background . '.svg');
    $backgroundContent = preg_replace('/<path /', '<path fill="#' . $matches['color'] . '" ', $backgroundContent);
    $backgroundSvg = new Imagick();
    $backgroundSvg->setBackgroundColor(new ImagickPixel('transparent'));
    $backgroundSvg->setResolution(300, 300);
    $backgroundSvg->readImageBlob($backgroundContent);
    $backgroundSvg->resizeImage($width + ($marging * 2), $height + ($marging * 2), Imagick::FILTER_LANCZOS, 1, true);
    $bgX = ($width - $backgroundSvg->getImageWidth()) / 2 -1;
    $image->compositeImage($backgroundSvg, Imagick::COMPOSITE_OVER, $bgX, 0);

    $svgContent = file_get_contents('../icons/maki/icons/' . $matches['name'] . '.svg');
    $svgContent = preg_replace('/<path /', '<path fill="#fff" ', $svgContent);
    $svg = new Imagick();
    $svg->setBackgroundColor(new ImagickPixel('transparent'));
    $svg->setResolution(300, 300);
    $svg->readImageBlob($svgContent);
    $svg->resizeImage($width - ($marging * 2), $height - ($marging * 2), Imagick::FILTER_LANCZOS, 1, true);

    $image->compositeImage($svg, Imagick::COMPOSITE_OVER, $marging, $marging);

    header('Content-Type: image/png');
    echo $image->getImageBlob();

    $backgroundStrokedSvg->destroy();
    $svg->destroy();
    $image->destroy();
    return;
} catch (\Exception $e) {
    http_response_code(500);
}
