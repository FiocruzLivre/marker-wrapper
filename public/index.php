<?php

require '../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Sabre\Xml\Service;
use Sabre\Xml\Writer;
use Spatie\Color\Hex;

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

enum Type: string {
    case PNG = 'png';
    case SVG = 'svg';
}

class Marker
{

    private string $iconColor = '#fff';
    private bool $header = false;
    private Type $type = Type::PNG;
    public function __construct(
        private string $size = 'm',
        private string $name = 'circle',
        private string $color = '#2b82cb',
    )
    {
    }

    public function setSize(string $size): self {
        $this->size = $size;
        return $this;
    }
    public function setName(string $name): self {
        $this->name = $name;
        return $this;
    }
    public function setColor(string $color): self {
        $this->color = $color;
        return $this;
    }
    private function extractD(string $filePath) {
        $xmlService = new Service();
        $xmlContent = file_get_contents($filePath);
        $parsed = $xmlService->parse($xmlContent);
        return $parsed[0]['attributes']['d'];
    }

    private function colorIntencity($hex, $factor) {
        $decimal = hexdec($hex);
        $new = round($decimal * $factor);
        if ($new > 255) {
            $new = 255;
        } elseif ($new < 0) {
            $new = 0;
        }
        return str_pad(dechex($new), strlen($hex), '0', STR_PAD_LEFT);
    }

    public function getSvg()
    {
        $markerColor = '#' . $this->color;
        $markerColorObject = Hex::fromString($markerColor);
        $strokedColor = (string) (new Hex(
            $this->colorIntencity($markerColorObject->red(), 1.6),
            $this->colorIntencity($markerColorObject->green(), 1.6),
            $this->colorIntencity($markerColorObject->blue(), 1.6),
        ));

        $background = 'marker';
        $backgroundStroked = $background . '-stroked';
        $marker = $this->extractD('../icons/maki/icons/' . $background . '.svg');
        $stroked = $this->extractD('../icons/maki/icons/' . $backgroundStroked . '.svg');
        $icon = $this->extractD('../icons/maki/icons/' . $this->name . '.svg');

        $writer = new Writer();
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('svg');
        extract($this->getSize());
        if ($this->type === Type::SVG) {
            $width *=10;
            $height *=10;
        }
        $writer->writeAttributes([
            'width' => (string) $width,
            'height' => (string) $height,
            'viewBox' => "0 0 15 15",
            'xmlns' => 'http://www.w3.org/2000/svg',
            'id' => 'tunnel'
        ]);

        $writer->write([
            [
                'name' => 'path',
                'attributes' => [
                    'd' => $marker,
                    'fill' => $markerColor,
                    'transform' => 'translate(0, 0) scale(1)',
                    'transform-origin' => 'center',
                    'id' => 'marker'
                ]
            ],
            [
                'name' => 'path',
                'attributes' => [
                    'd' => $stroked,
                    'fill' => $strokedColor,
                    'transform' => 'translate(0, 0) scale(1)',
                    'transform-origin' => 'center',
                    'id' => 'stroked'
                ]
            ],
            [
                'name' => 'path',
                'attributes' => [
                    'd' => $icon,
                    'fill' => $this->iconColor,
                    'transform' => 'translate(4.6, 2.7) scale(0.4)',
                    // 'transform-origin' => 'center',
                    'id' => 'icon',
                ]
            ]
        ]);

        $writer->endElement();
        return $writer->outputMemory();
    }

    private function getSize(): array
    {
        switch($this->size) {
            case 's':
                return [
                    'width' => 20,
                    'height' => 25,
                ];
            case 'm':
                return [
                    'width' => 30,
                    'height' => 35,
                ];
            case 'l':
            default:
                return [
                    'width' => 35,
                    'height' => 45,
                ];
        }
    }

    public function getPng()
    {
        $svgContent = $this->getSvg();
        extract($this->getSize());

        $image = new Imagick();
        $image->newImage($width, $height * 2, new ImagickPixel('transparent'), 'png');

        $svg = new Imagick();
        $svg->setBackgroundColor(new ImagickPixel('transparent'));
        $svg->setResolution(300, 300);
        $svg->readImageBlob($svgContent);
        $svg->resizeImage(round($width*1.3), round($height*1.3), Imagick::FILTER_LANCZOS, 1, true);

        $image->compositeImage($svg, Imagick::COMPOSITE_OVER, -5, -3);

        return $image->getImageBlob();
    }

    public function withHeader(): self
    {
        $this->header = true;
        return $this;
    }

    public function imageType(Type $type): self {
        $this->type = $type;
        return $this;
    }

    public function output(): mixed {
        switch ($this->type) {
            case Type::SVG:
                $output = $this->getSvg();
                if ($this->header) {
                    header('Content-Type: application/xml');
                    echo $output;
                }
                return $output;
            case Type::PNG:
                $output = $this->getPng();
                if ($this->header) {
                    header('Content-Type: image/png');
                    echo $output;
                }
                return $output;
        }
    }
}

preg_match('/^\/v4\/marker\/(pin-(?<size>[a-z]+))?-?(?<name>[a-z-]+)?\+?(?<color>[a-f0-9]{3,6})?\.(?<format>png|svg)$/i', $requestPath, $matches);
if (!$matches) {
    http_response_code(500);
    return;
}

$marker = new Marker();
$marker
    ->setSize($matches['size'])
    ->setName($matches['name'])
    ->setColor($matches['color'])
    ->withHeader()
    ->imageType(Type::tryFrom($matches['format']))
    ->output();