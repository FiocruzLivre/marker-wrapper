<?php
declare(strict_types=1);

namespace FiocruzLivre\MarkerWrapper;

use Imagick;
use ImagickPixel;
use RuntimeException;

final class StaticMapRenderer
{
    /**
     * Render only tiles needed for this requested viewport. Never prefetch.
     *
     * @param callable(int,int,int):HttpResponse $tile
     */
    public function render(
        int $zoom,
        float $lat,
        float $lon,
        int $width,
        int $height,
        callable $tile,
        float $scale = 1.0,
    ): string {
        if ($zoom < 0 || $zoom > 18
            || $lat < -85.05112878 || $lat > 85.05112878
            || $lon < -180 || $lon > 180
            || $width < 1 || $height < 1 || $width > 1200 || $height > 1200
            || $width * $height > 1500000
            || !in_array($scale, [1.0, 1.3, 1.5, 2.0, 2.6, 3.0], true)
            || $width * $height * $scale * $scale > 6000000
        ) {
            throw new \InvalidArgumentException('Unsupported map extent');
        }
        $tilesInWorld = 1 << $zoom;
        $size = $tilesInWorld * 256;
        $sin = sin(deg2rad($lat));
        $centerX = ($lon + 180) / 360 * $size;
        $centerY = (0.5 - log((1 + $sin) / (1 - $sin)) / (4 * M_PI)) * $size;
        $left = $centerX - $width / 2;
        $top = $centerY - $height / 2;

        $minX = (int) floor($left / 256);
        $maxX = (int) floor(($left + $width - 1) / 256);
        $minY = (int) floor($top / 256);
        $maxY = (int) floor(($top + $height - 1) / 256);
        if (($maxX - $minX + 1) * ($maxY - $minY + 1) > 48) {
            throw new \InvalidArgumentException('Too many tiles');
        }

        $image = new Imagick();
        $image->newImage($width, $height, new ImagickPixel('white'), 'png');

        for ($ty = $minY; $ty <= $maxY; $ty++) {
            if ($ty < 0 || $ty >= $tilesInWorld) {
                continue;
            }
            for ($tx = $minX; $tx <= $maxX; $tx++) {
                $wrappedX = (($tx % $tilesInWorld) + $tilesInWorld) % $tilesInWorld;
                $response = $tile($zoom, $wrappedX, $ty);
                if ($response->status !== 200) {
                    throw new RuntimeException('Tile provider not available');
                }
                $piece = new Imagick();
                $piece->readImageBlob($response->body);
                $image->compositeImage(
                    $piece,
                    Imagick::COMPOSITE_OVER,
                    (int) round($tx * 256 - $left),
                    (int) round($ty * 256 - $top),
                );
                $piece->clear();
            }
        }
        if ($scale !== 1.0) {
            $image->resizeImage((int) round($width * $scale), (int) round($height * $scale), Imagick::FILTER_LANCZOS, 1);
        }
        $image->setImageFormat('png');
        $blob = $image->getImagesBlob();
        $image->clear();
        return $blob;
    }
}
