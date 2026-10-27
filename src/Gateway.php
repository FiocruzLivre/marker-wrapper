<?php

declare(strict_types=1);

namespace FiocruzLivre\MarkerWrapper;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

final readonly class Gateway
{
    public function __construct(
        private ClientInterface $client,
        private DiskCache $cache,
        private MarkerRenderer $markers,
        private StaticMapRenderer $maps,
        private string $tileServer,
        private string $userAgent,
        private string $referer = '',
        private CachePolicy $cachePolicy = new CachePolicy(),
    ) {
        $parts = parse_url($tileServer);
        if (!is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || trim($userAgent) === ''
            || ($referer !== '' && !str_starts_with($referer, 'https://'))) {
            throw new \InvalidArgumentException('Invalid tile provider configuration');
        }
    }

    public function handle(string $uri): HttpResponse
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path)) {
            return $this->error(400);
        }
        if ($path === '/healthz') {
            return new HttpResponse(200, "ok\n", ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        if (preg_match('~^/v4/marker/pin-(?<size>[sml])(?:-(?<icon>[a-z][a-z0-9-]{0,40}))?\\+(?<color>[a-fA-F0-9]{3}|[a-fA-F0-9]{6})\\.(?<format>png|svg)$~D', $path, $m)) {
            $name = $m['icon'] ?: 'circle';
            $format = $m['format'];
            $cacheKey = 'marker:' . $path;
            $cached = $this->cache->read($cacheKey, $this->cachePolicy->markerTtl);
            if ($cached !== null) {
                return $this->image(200, $cached, $format === 'svg' ? 'image/svg+xml' : 'image/png', $this->cachePolicy->markerTtl);
            }
            try {
                $data = $this->markers->render($m['size'], $name, $m['color'], $format);
            } catch (\OutOfBoundsException) {
                return $this->error(404);
            } catch (\Throwable $e) {
                error_log('MarkerWrapper marker error: ' . get_class($e));
                return $this->error(500);
            }
            $this->cache->write($cacheKey, $data);
            return $this->image(200, $data, $format === 'svg' ? 'image/svg+xml' : 'image/png', $this->cachePolicy->markerTtl);
        }

        if (preg_match('~^/(?:osm/)?(?<z>[0-9]{1,2})/(?<x>[0-9]{1,7})/(?<y>[0-9]{1,7})\\.png$~D', $path, $m)) {
            return $this->tile((int) $m['z'], (int) $m['x'], (int) $m['y']);
        }

        if (preg_match('~^/img/(?<style>osm|osm-intl)?,(?<z>[0-9]{1,2}),(?<lat>-?[0-9]{1,3}(?:\\.[0-9]+)?),(?<lon>-?[0-9]{1,3}(?:\\.[0-9]+)?),(?<w>[0-9]{1,4})x(?<h>[0-9]{1,4})(?:@(?<scale>1\\.3|1\\.5|2|2\\.6|3)x)?\\.png$~D', $path, $m)) {
            $key = 'static:' . $path;
            $cached = $this->cache->read($key, $this->cachePolicy->staticTtl);
            if ($cached !== null) {
                return $this->image(200, $cached, 'image/png', $this->cachePolicy->staticTtl);
            }
            try {
                $scale = isset($m['scale']) && $m['scale'] !== '' ? (float) $m['scale'] : 1.0;
                $result = $this->maps->render(
                    (int) $m['z'],
                    (float) $m['lat'],
                    (float) $m['lon'],
                    (int) $m['w'],
                    (int) $m['h'],
                    fn (int $z, int $x, int $y): HttpResponse => $this->tile($z, $x, $y),
                    $scale,
                );
            } catch (\InvalidArgumentException) {
                return $this->error(400);
            } catch (\Throwable $e) {
                error_log('MarkerWrapper static map error: ' . get_class($e));
                return $this->error(502);
            }
            $this->cache->write($key, $result);
            return $this->image(200, $result, 'image/png', $this->cachePolicy->staticTtl);
        }

        // Never operate as an open proxy for caller-chosen paths or hosts.
        return $this->error(404);
    }

    public function tile(int $z, int $x, int $y): HttpResponse
    {
        if ($z < 0 || $z > 19 || $x < 0 || $y < 0 || $x >= 2 ** $z || $y >= 2 ** $z) {
            return $this->error(400);
        }
        $key = "tile:$z/$x/$y";
        $cached = $this->cache->read($key, $this->cachePolicy->tileTtl);
        if ($cached !== null) {
            return $this->image(200, $cached, 'image/png', $this->cachePolicy->tileTtl);
        }

        try {
            $headers = [
                'User-Agent' => $this->userAgent,
                'Accept' => 'image/png',
            ];
            if ($this->referer !== '') {
                $headers['Referer'] = $this->referer;
            }
            $upstream = $this->client->request(
                'GET',
                rtrim($this->tileServer, '/') . "/$z/$x/$y.png",
                [
                    'allow_redirects' => false,
                    'http_errors' => false,
                    'timeout' => 12,
                    'connect_timeout' => 4,
                    'headers' => $headers,
                ],
            );
        } catch (GuzzleException $e) {
            error_log('MarkerWrapper tile request failed: ' . get_class($e));
            return $this->error(502);
        }
        $status = $upstream->getStatusCode();
        if ($status !== 200) {
            return $this->error(in_array($status, [403, 404, 429], true) ? $status : 502);
        }
        $data = (string) $upstream->getBody();
        if (strlen($data) > 2000000 || !str_starts_with($data, "\x89PNG\r\n\x1a\n")) {
            return $this->error(502);
        }
        $this->cache->write($key, $data);
        return $this->image(200, $data, 'image/png', $this->cachePolicy->tileTtl);
    }

    private function image(int $status, string $body, string $type, int $ttl): HttpResponse
    {
        return new HttpResponse($status, $body, [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=' . $ttl,
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    private function error(int $status): HttpResponse
    {
        return new HttpResponse($status, "Resource unavailable\n", [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }
}
