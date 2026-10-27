# Development and maintenance

## Local setup

Use Docker Compose v2, Git (with submodules) and Composer 2. PHP 8.5 with
Imagick is needed for running tests outside the runtime image.

1. `git submodule update --init --recursive`
2. Create a local `.env` containing `TILE_SERVER` (approved HTTPS XYZ tile
   provider) and `MARKER_USER_AGENT` (identifiable name and contact).
   Configure other values only if needed; read the comments in
   `docker-compose.yml`.
3. `docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build`
4. `curl --fail http://127.0.0.1:18181/healthz`

The standard `docker-compose.yml` does not publish ports. The development
override publishes Nginx on loopback port 18181 only. PHP-FPM listens
internally on 9000 and Nginx on 8080. Do not remove a cache volume when
restarting the application.

## Quality checks

```sh
composer install --no-interaction
composer run tools:install
composer run lint
composer run test
composer run cs:check
composer run psalm
composer run security:check
```

The isolated `vendor-bin/phpunit`, `vendor-bin/psalm` and
`vendor-bin/coding-standard` manifests each have their own lock file.
The CI also builds the production Docker image and tests internal routes
without publishing host ports.

## Gateway behavior and cache

- Verify `/healthz`, `/0/0/0.png`, `/img/osm,0,0,0,180x180.png` and
  `/v4/marker/pin-m+2b82cb.png`; check PNG signature and MIME.
- Test invalid paths and malformed input; only permitted routes and the
  configured upstream host may be used.
- Test high-density screen requests such as
  `/osm/5/13/19@1.3x.png` and `/v4/marker/pin-l+7e7e7e@2x.png`;
  verify decoded PNG dimensions, not only HTTP 200. The same base tile
  must be fetched at most once from the configured provider.
- Mapbox marker images reserve 20x50, 30x70 and 35x90 pixels for
  small, medium and large sizes respectively (double at `@2x`). The
  visible pin occupies the upper half; the remainder stays transparent
  for the anchor. Do not add an icon when none was requested. Marker
  cache keys are versioned when rendered image geometry changes.
- Persistent disk cache defaults: tiles 7 days, static maps 1 day,
  markers 30 days; TTLs can be overridden via Compose.
- To prune expired cache entries without disrupting service:
  `docker compose exec -T marker php /app/bin/prune-cache.php`.
  Schedule externally as required; no periodic job runs inside the
  container.

## Dependency maintenance and release

Dependabot monitors root Composer, all three developer tool environments,
the nested PHP Dockerfile, the root Compose image, the Maki Git submodule
and GitHub Actions. Its scheduled version updates use configuration from
the repository's default branch, not a draft pull request.

Manual review is still needed for the extension-installer release URL and
SHA-256 in the Dockerfile: Dependabot cannot reliably maintain the
downloaded script/checksum pair. CI must validate changes before merge.

Build a tested image off the production server. On deployment preserve
the previous image, config, cache volume and network wiring so traffic
can be switched back without deleting data. Interactive GeoJSON overlay
rendering is client-side; static images contain base tiles only.
