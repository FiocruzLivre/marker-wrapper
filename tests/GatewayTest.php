<?php

declare(strict_types=1);

namespace FiocruzLivre\MarkerWrapper\Tests;

use FiocruzLivre\MarkerWrapper\CachePolicy;
use FiocruzLivre\MarkerWrapper\DiskCache;
use FiocruzLivre\MarkerWrapper\Gateway;
use FiocruzLivre\MarkerWrapper\MarkerRenderer;
use FiocruzLivre\MarkerWrapper\StaticMapRenderer;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Imagick;
use ImagickPixel;
use PHPUnit\Framework\TestCase;

final class GatewayTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/marker-test-' . bin2hex(random_bytes(6));
        mkdir($this->cacheDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*/*.bin') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->cacheDir . '/*') ?: [] as $dir) {
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
        rmdir($this->cacheDir);
    }

    /** @param list<Response> $upstream */
    private function gateway(
        array $upstream = [],
        ?array &$history = null,
        ?CachePolicy $policy = null,
        string $referer = 'https://example.org/',
    ): Gateway {
        $handler = HandlerStack::create(new MockHandler($upstream));
        $history = [];
        $handler->push(Middleware::history($history));
        return new Gateway(
            new Client(['handler' => $handler]),
            new DiskCache($this->cacheDir),
            new MarkerRenderer(dirname(__DIR__) . '/icons/maki/icons'),
            new StaticMapRenderer(),
            'https://tiles.example.invalid',
            'MarkerWrapperTest/2.0 (+https://example.org/contact)',
            $referer,
            $policy ?? new CachePolicy(),
        );
    }

    private function png(): string
    {
        $image = new Imagick();
        $image->newImage(256, 256, new ImagickPixel('lightblue'), 'png');
        $image->setImageFormat('png');
        $result = $image->getImageBlob();
        $image->clear();
        return $result;
    }

    public function testHealthAndUnknownRouteNeverContactUpstream(): void
    {
        $history = [];
        $gateway = $this->gateway([], $history);
        self::assertSame(200, $gateway->handle('/healthz')->status);
        self::assertSame(404, $gateway->handle('/foo/anything')->status);
        self::assertSame(404, $gateway->handle('/https://127.0.0.1/private')->status);
        self::assertCount(0, $history);
    }

    public function testTileValidationPreventsArbitraryRequests(): void
    {
        $gateway = $this->gateway();
        foreach (['/20/1/1.png', '/5/64/0.png', '/5/0/64.png'] as $path) {
            self::assertSame(400, $gateway->handle($path)->status);
        }
        self::assertSame(404, $gateway->handle('/unrelated/route')->status);
    }

    public function testTileProxyIdentifiesApplicationCachesAndDoesNotRewriteHosts(): void
    {
        $history = [];
        $gateway = $this->gateway([new Response(200, ['Content-Type' => 'image/png'], $this->png())], $history);
        $result = $gateway->handle('/osm/0/0/0.png?lang=pt-br');
        self::assertSame(200, $result->status);
        self::assertSame('image/png', $result->headers['Content-Type']);
        self::assertSame('public, max-age=604800', $result->headers['Cache-Control']);
        self::assertSame(200, $gateway->handle('/0/0/0.png')->status);
        self::assertCount(1, $history);
        self::assertSame('https://tiles.example.invalid/0/0/0.png', (string) $history[0]['request']->getUri());
        self::assertStringContainsString('MarkerWrapperTest', $history[0]['request']->getHeaderLine('User-Agent'));
        self::assertSame('https://example.org/', $history[0]['request']->getHeaderLine('Referer'));
    }

    public function testProviderDenialsAreNotMaskedAsSuccess(): void
    {
        $gateway = $this->gateway([new Response(429, [], 'rate limited')]);
        $result = $gateway->handle('/0/0/0.png');
        self::assertSame(429, $result->status);
        self::assertSame('no-store', $result->headers['Cache-Control']);
    }

    public function testHtmlProviderResponseIsRejectedNotServedAsPng(): void
    {
        $gateway = $this->gateway([new Response(200, ['Content-Type' => 'text/html'], '<html>not an image</html>')]);
        self::assertSame(502, $gateway->handle('/0/0/0.png')->status);
    }

    public function testRealMarkerSvgAndPngCarryCorrectTypes(): void
    {
        $gateway = $this->gateway();
        $svg = $gateway->handle('/v4/marker/pin-m+2b82cb.svg');
        self::assertSame(200, $svg->status);
        self::assertSame('image/svg+xml', $svg->headers['Content-Type']);
        self::assertStringContainsString('<svg', $svg->body);
        $png = $gateway->handle('/v4/marker/pin-m-circle+2b82cb.png');
        self::assertSame(200, $png->status);
        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $png->body);
        self::assertSame(404, $gateway->handle('/v4/marker/pin-m-unknownmarker+fff.png')->status);
    }

    public function testStaticMapUsesOnlyVisibleTilesAndBoundsMemory(): void
    {
        $history = [];
        $png = $this->png();
        $gateway = $this->gateway([new Response(200, ['Content-Type' => 'image/png'], $png)], $history);
        $result = $gateway->handle('/img/,0,0,0,180x180.png');
        self::assertSame(200, $result->status);
        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $result->body);
        self::assertCount(1, $history);
        self::assertSame(400, $gateway->handle('/img/,1,0,0,9999x9999.png')->status);
    }

    public function testDisallowsNonHttpsTileProviders(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Gateway(
            new Client(),
            new DiskCache($this->cacheDir),
            new MarkerRenderer(dirname(__DIR__) . '/icons/maki/icons'),
            new StaticMapRenderer(),
            'http://127.0.0.1/internal',
            'valid-agent',
            'https://example.org/',
        );
    }


    public function testOperatorConfiguredCacheDurationsApplyToResponses(): void
    {
        $policy = new CachePolicy(604800, 1200, 3600);
        $history = [];
        $gateway = $this->gateway(
            [new Response(200, ['Content-Type' => 'image/png'], $this->png())],
            $history,
            $policy,
        );

        $tile = $gateway->handle('/0/0/0.png');
        self::assertSame('public, max-age=604800', $tile->headers['Cache-Control']);
        $marker = $gateway->handle('/v4/marker/pin-m+2b82cb.png');
        self::assertSame('public, max-age=3600', $marker->headers['Cache-Control']);
        $static = $gateway->handle('/img/,0,0,0,180x180.png');
        self::assertSame('public, max-age=1200', $static->headers['Cache-Control']);
        self::assertCount(1, $history, 'Static map must reuse a cached tile');
    }

    public function testOptionalRefererIsNotInvented(): void
    {
        $history = [];
        $gateway = $this->gateway(
            [new Response(200, ['Content-Type' => 'image/png'], $this->png())],
            $history,
            null,
            '',
        );
        self::assertSame(200, $gateway->handle('/0/0/0.png')->status);
        self::assertSame('', $history[0]['request']->getHeaderLine('Referer'));
    }

    public function testCachePolicyRejectsInvalidLifetime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CachePolicy(59);
    }

    public function testCacheIsPersistedAndExpiresByConfiguredLifetime(): void
    {
        $cache = new DiskCache($this->cacheDir);
        $cache->write('example-key', 'cached-response');
        self::assertSame('cached-response', $cache->read('example-key', 3600));
        self::assertNull($cache->read('example-key', 0));
    }

    public function testExplicitPruneKeepsFreshCacheAndDeletesExpiredCache(): void
    {
        $cache = new DiskCache($this->cacheDir);
        $cache->write('old-entry', 'expired');
        $cache->write('new-entry', 'current');
        $hash = hash('sha256', 'old-entry');
        $expired = $this->cacheDir . '/' . substr($hash, 0, 2) . '/' . $hash . '.bin';
        self::assertTrue(touch($expired, time() - 86401));
        clearstatcache(true, $expired);

        self::assertSame(1, $cache->pruneExpired(86400));
        self::assertNull($cache->read('old-entry', 86400));
        self::assertSame('current', $cache->read('new-entry', 86400));
    }

    public function testInvalidEnvironmentCacheLifetimeIsRejected(): void
    {
        $previous = getenv('TILE_CACHE_TTL');
        try {
            putenv('TILE_CACHE_TTL=not-an-integer');
            $this->expectException(\InvalidArgumentException::class);
            CachePolicy::fromEnvironment();
        } finally {
            if ($previous === false) {
                putenv('TILE_CACHE_TTL');
            } else {
                putenv('TILE_CACHE_TTL=' . $previous);
            }
        }
    }
}
