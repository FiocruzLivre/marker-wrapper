# Marker Wrapper — Mapa Mov Saúde

A restricted Kartographer gateway for raster XYZ tiles, local static-map
rendering and the legacy Maki marker path (`/v4/marker/pin-*.png`).

## Why this exists

Kartographer expects **three** distinct features: tiles `/{z}/{x}/{y}.png`,
static images `/img/{style},{z},{lat},{lon},{width}x{height}.png`, and
custom markers `/v4/marker/...`. A bare XYZ endpoint implements only the
first. The former proxy forwarded any requested path to a configured upstream
and responded HTTP 500 to upstream policy denials. Old marker requests also
leaked PHP warnings into images. This revision explicitly implements routes
rather than blindly forwarding arbitrary paths.

Wikimedia's tile service is **not** available to unrelated third-party sites.
Do not use this gateway to bypass its access restrictions. Configure a tile
provider that authorizes the site's use; the default `tile.openstreetmap.org`
is suitable only for modest, policy-compliant traffic. Observe its published
user-agent, attribution, referer, cache and no-prefetch requirements.

## Local / staging

```sh
git clone --recurse-submodules https://github.com/FiocruzLivre/marker-wrapper
cd marker-wrapper
docker compose up -d --build
curl -i http://127.0.0.1:18181/healthz
curl -o /tmp/map.png http://127.0.0.1:18181/img/,5,-16,-54,360x200.png
curl -o /tmp/pin.png http://127.0.0.1:18181/v4/marker/pin-m+2b82cb.png
```

All published development ports bind to loopback. The app image bundles
dependencies and a pinned Maki icons submodule; it never runs Composer or
downloads code while serving HTTP requests. The cache uses a persistent Docker
volume. Create the instance-specific reverse proxy separately and test TLS,
headers and origins before any production change.

Configurable environment variables:

- `TILE_SERVER`: fixed HTTPS XYZ tile origin, not chosen by the caller
- `MARKER_USER_AGENT`: stable, identifiable app user agent/contact URL
- `MARKER_REFERER`: actual public wiki origin, using HTTPS
- `MARKER_PORT`: loopback HTTP port for local tests (default 18181)
- `CACHE_DIR`: container writable cache volume (default /app/cache)

`composer.lock` pins PHP libraries, the Dockerfile pins PHP 8.5.12 and
extension installer 2.12.3 by SHA-256, and `icons/maki` is a Git submodule
fixed at a specific commit. Dependabot proposes controlled updates; CI checks
Composer advisories, linting, PHPUnit and the Docker Compose runtime.

## Integration

In the wiki's **site-specific** configuration, after deploying and validating
the gateway on an accessible HTTPS domain:

```php
$wgKartographerMapServer = 'https://marker.mapamovsaude.net.br';
$wgKartographerDfltStyle = '';
$wgKartographerStyles = ['osm'];
$wgKartographerSimpleStyleMarkers = true;
```

Do not activate this configuration until the reverse proxy really serves
tiles, static images, and marker URLs. A broken proxy cannot be fixed by
altering MediaWiki's Parser.php.

## Compatibility and limits

- Supports XYZ paths with optional `/osm/`, bounded static-map rasterization
  with a maximum of 1.5 million base pixels and 48 tile operations, and Maki
  icons in SVG or PNG. Invalid routes and marker names return 404/400.
- Static images are assembled *on demand* from the visible XYZ tiles, without
  background prefetch. Interactive Kartographer overlays render in the browser.
  Static snapshots currently contain the **base map only**, not GeoJSON
  overlays. Verify this limitation is acceptable before relying on no-JS
  screenshots, print or crawlers; a separate overlay renderer would be needed
  for full parity.
- Upstream 403/429 responses retain their error codes; redirects are not
  followed; an unexpected HTML response never masquerades as an image.
- Disk cache keeps XYZ tiles for seven days and no external tile is retrieved
  merely to populate the cache. Local image caching is separate from MediaWiki
  parser caching.
- The public tile service is donated infrastructure. Monitor traffic, respect
  attribution, do not bulk-download maps, and use a dedicated tile provider if
  traffic exceeds the service's permitted use.

## Production and rollback

The marker gateway can be deployed independently to repair maps in the
existing MediaWiki 1.41 installation, provided the gateway integration, public
TLS route, browser map/marker display and immediate rollback are rehearsed.
The separate MediaWiki 1.46 migration still requires authenticated editing,
VisualEditor, semantic content, mobile layout and full backup rehearsal.
Build and audit images **off** the ~2 GB production host. Record immutable
source/lock/submodule references and test the reverse proxy privately before
switching Kartographer's server URL. Keep the old service running during
verification where memory permits, or deploy during a short maintenance window.

Rollback the wiki's `$wgKartographerMapServer` to the previous known-good
provider **only if it was independently verified**. Otherwise, restore the
previous tested service image and configuration together; do not revert
MediaWiki's migrated schema by image swap. A full wiki rollback additionally
requires its matching pre-migration database, uploads and settings snapshot.
