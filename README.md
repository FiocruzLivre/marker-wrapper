# Marker Wrapper

A configurable PHP gateway that serves XYZ map tiles, static map images and
Maki markers using a tile provider authorized by the operator. Compatible with
MediaWiki Kartographer; not coupled to a particular wiki or domain.

## Endpoints

- `/{z}/{x}/{y}.png` and `/osm/{z}/{x}/{y}.png`: raster XYZ tiles
- `/img/osm,{z},{lat},{lon},{width}x{height}.png`: static map image
- `/v4/marker/pin-{size}+{color}.png` (or `.svg`): rendered marker
- `/healthz`: health check

Only supported routes are accepted; callers cannot supply arbitrary upstream
hosts. Map snapshots contain base tiles, not GeoJSON overlays.

## Configuration

Copy `.env.example` to `.env` and set:

| Variable | Meaning |
| --- | --- |
| `TILE_SERVER` | Required: an authorized HTTPS XYZ tile origin |
| `MARKER_USER_AGENT` | Required: identifiable service name and contact |
| `MARKER_REFERER` | Optional HTTPS origin identifying the operator |
| `CACHE_DIR` | Writable directory for persistent cached responses |
| `TILE_CACHE_TTL` | Tile retention, default 604800 seconds (7 days) |
| `STATIC_CACHE_TTL` | Static images, default 86400 seconds (1 day) |
| `MARKER_CACHE_TTL` | Generated markers, default 2592000 seconds (30 days) |

TTLs must be between 60 seconds and 365 days. Honor the chosen provider's
licensing, attribution, cache and usage policy. For the public OpenStreetMap
tile service, cache tiles at least seven days unless upstream headers specify
otherwise; do not prefetch. Cache entries are stored in a named Docker volume,
written atomically and reused across container restarts. Error responses
are never cached. Pruning is an explicit maintenance operation, never part of
HTTP request handling. Schedule daily:

```sh
docker compose exec -T marker php /app/bin/prune-cache.php
```

Entries older than the longest configured lifetime are deleted. Keep the
persistent volume when replacing containers.

## Runtime

```sh
git clone --recurse-submodules https://github.com/FiocruzLivre/marker-wrapper.git
cd marker-wrapper
cp .env.example .env
# Edit .env with your approved tile provider and identity.
docker compose up -d --build
```

PHP-FPM listens on port 9000 **inside the Docker network** and Nginx on 8080.
The default Compose file publishes **no host ports**. Connect the Nginx service
to your existing reverse proxy network. For local development only:

```sh
docker compose -f compose.yaml -f compose.dev.yaml up -d --build
curl http://127.0.0.1:18181/healthz
```

## Development and checks

```sh
composer install
composer run tools:install
composer run lint
composer run test
composer run cs:check
composer run psalm
composer run security:check
composer run cache:prune
```

PHPUnit, Psalm and PHP-CS-Fixer have isolated, locked `vendor-bin/`
dependencies. Production installation excludes them. CI builds the image,
executes the quality tools and validates internal HTTP routes. Dependabot
updates root, isolated Composer manifests, Docker and GitHub Actions.

For production, build and test an immutable image before deployment; capture
existing container, network and proxy configuration for rollback. No database
or MediaWiki core changes are needed to update this gateway.
