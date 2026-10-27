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
    public function __construct(private string $iconsDirectory)
    {
    }

    public function render(string $size, ?string $name, string $color, string $format, float $scale = 1.0): string
    {
        if (!in_array($size, ['s', 'm', 'l'], true)
            || ($name !== null && !preg_match('/^[a-z][a-z0-9-]{0,40}$/D', $name))
            || !preg_match('/^(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/D', $color)
            || !in_array($format, ['png', 'svg'], true)
            || !in_array($scale, [1.0, 1.3, 1.5, 2.0, 2.6, 3.0], true)) {
            throw new \InvalidArgumentException('Invalid marker');
        }

        $marker = $this->pathData('marker');
        $border = $this->pathData('marker-stroked');
        $icon = $name !== null ? $this->pathData($name) : null;

        $color = strtolower($color);
        if (strlen($color) === 3) {
            $color = $color[0] . $color[0] . $color[1] . $color[1] . $color[2] . $color[2];
        }
        $outline = '';
        foreach (str_split($color, 2) as $part) {
            $outline .= str_pad(dechex(max(0, (int) round((float) hexdec($part) * 0.72))), 2, '0', STR_PAD_LEFT);
        }
        // Mapbox/Leaflet reserves twice the pin's height so that its
        // anchor (half the image height) is aligned with the pin's tip.
        // The lower half must remain transparent rather than stretching
        // the pin vertically to fill the whole icon image.
        [$width, $height] = match ($size) {
            's' => [20, 50],
            'm' => [30, 70],
            'l' => [35, 90],
        };
        $width = (int) round((float) $width * $scale);
        $height = (int) round((float) $height * $scale);

        // A symbol is optional in the Mapbox marker API. Do not render
        // an empty or artificially substituted icon for plain pins.
        $symbol = $icon !== null
            ? sprintf(
                '<path d="%s" fill="#fff" transform="translate(4.6 2.7) scale(0.4)"/>',
                htmlspecialchars($icon, ENT_QUOTES | ENT_XML1),
            )
            : '';
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 15 30" preserveAspectRatio="none"><g transform="translate(-3 0) scale(1.4 1)"><path d="%s" fill="#%s"/><path d="%s" fill="#%s"/>%s</g></svg>',
            $width,
            $height,
            htmlspecialchars($marker, ENT_QUOTES | ENT_XML1),
            $color,
            htmlspecialchars($border, ENT_QUOTES | ENT_XML1),
            $outline,
            $symbol,
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
        $node = $nodes->item(0);
        if (!$node instanceof \DOMElement) {
            throw new RuntimeException('Icon SVG missing path element');
        }
        $d = $node->getAttribute('d');
        if ($d === '') {
            throw new RuntimeException('Icon SVG missing path data');
        }
        return $d;
    }
}
