<?php
declare(strict_types=1);

namespace FiocruzLivre\MarkerWrapper;

use DOMDocument;
use DOMXPath;
use Imagick;
use ImagickPixel;
use RuntimeException;

final readonly class MarkerRenderer
{
    public function __construct(private string $iconsDirectory) {}

    public function render(string $size, string $name, string $color, string $format): string
    {
        if (!in_array($size, ['s', 'm', 'l'], true)
            || !preg_match('/^[a-z][a-z0-9-]{0,40}$/D', $name)
            || !preg_match('/^(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/D', $color)
            || !in_array($format, ['png', 'svg'], true)) {
            throw new \InvalidArgumentException('Invalid marker');
        }

        $marker = $this->pathData('marker');
        $border = $this->pathData('marker-stroked');
        $icon = $this->pathData($name);

        $color = strtolower($color);
        if (strlen($color) === 3) {
            $color = $color[0] . $color[0] . $color[1] . $color[1] . $color[2] . $color[2];
        }
        $outline = '';
        foreach (str_split($color, 2) as $part) {
            $outline .= str_pad(dechex(min(255, (int)round(hexdec($part) * 1.6))), 2, '0', STR_PAD_LEFT);
        }
        [$width, $height] = match ($size) {
            's' => [20, 25],
            'm' => [30, 35],
            'l' => [35, 45],
        };
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 15 15"><path d="%s" fill="#%s"/><path d="%s" fill="#%s"/><path d="%s" fill="#fff" transform="translate(4.6 2.7) scale(0.4)"/></svg>',
            $width, $height, htmlspecialchars($marker, ENT_QUOTES | ENT_XML1), $color,
            htmlspecialchars($border, ENT_QUOTES | ENT_XML1), $outline,
            htmlspecialchars($icon, ENT_QUOTES | ENT_XML1),
        );
        if ($format === 'svg') {
            return $svg;
        }
        $image = new Imagick();
        $image->setBackgroundColor(new ImagickPixel('transparent'));
        $image->readImageBlob($svg);
        $image->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1);
        $image->setImageFormat('png');
        $result = $image->getImagesBlob();
        $image->clear();
        return $result;
    }

    private function pathData(string $name): string
    {
        $file = rtrim($this->iconsDirectory, '/') . '/' . $name . '.svg';
        if (!is_file($file)) {
            throw new \OutOfBoundsException('Unknown marker icon');
        }
        $xml = new DOMDocument();
        $xml->resolveExternals = false;
        $xml->substituteEntities = false;
        if (!@$xml->load($file, LIBXML_NONET)) {
            throw new RuntimeException('Invalid icon SVG');
        }
        $nodes = (new DOMXPath($xml))->query('//*[local-name()="path"]');
        if ($nodes === false || $nodes->length < 1) {
            throw new RuntimeException('Icon SVG missing path');
        }
        $d = $nodes->item(0)?->attributes?->getNamedItem('d')?->nodeValue;
        if ($d === null || $d === '') {
            throw new RuntimeException('Icon SVG missing path data');
        }
        return $d;
    }
}
