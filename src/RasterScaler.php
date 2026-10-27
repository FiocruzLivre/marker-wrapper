<?php

declare(strict_types=1);

namespace FiocruzLivre\MarkerWrapper;

use Imagick;
use RuntimeException;

final class RasterScaler
{
    /**
     * Scale a cached XYZ tile for a high-density display.
     *
     * The upstream provider is always requested at the base resolution;
     * display variants are generated locally and cached independently.
     */
    public function scalePng(string $source, float $scale): string
    {
        if (!in_array($scale, [1.3, 1.5, 2.0, 2.6, 3.0], true)) {
            throw new \InvalidArgumentException('Unsupported image scale');
        }

        $image = new Imagick();
        try {
            $image->readImageBlob($source);
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            if ($width !== 256 || $height !== 256) {
                throw new RuntimeException('Expected 256x256 XYZ raster tile');
            }

            $image->resizeImage(
                (int) round((float) $width * $scale),
                (int) round((float) $height * $scale),
                Imagick::FILTER_LANCZOS,
                1,
            );
            $image->setImageFormat('png');

            return $image->getImageBlob();
        } finally {
            $image->clear();
        }
    }
}
