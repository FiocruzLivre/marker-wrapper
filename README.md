How to test

Add this to your composer-json.yml

```yaml
  marker:
    build:
      context: ./volumes/marker/.docker/php
    volumes:
      - ./volumes/marker:/app
    environment:
      - HOST_UID=${HOST_UID:-1000}
      - HOST_GID=${HOST_GID:-1000}
      - XDEBUG_CONFIG
      - TILE_SERVER
      - TZ
  nginx:
    image: nginx:alpine
    restart: unless-stopped
    volumes:
      - ./volumes/marker:/app:ro
      - ./volumes/marker/.docker/nginx/nginx.conf:/etc/nginx/conf.d/default.conf
    ports:
      - ${MARKER_PORT:-8081}:80
```

Clone this repository at `volumes/marker`:

```bash
git clone https://github.com/FiocruzLivre/marker-wrapper volumes/marker
```

Clone the follow repository at volumes/marker/icons/

```bash
git clone https://github.com/mapbox/maki/ volumes/marker/icons/marki

```

Change this at your LocalSettings.php:

```php
$wgKartographerMapServer = 'http://localhost:8081';
$wgKartographerSimpleStyleMarkers = true;
```

At your `.env` file add the URL of your tile server to var `TILE_SERVER`

Run the follow commands

```bash
docker compose exec marker composer i --no-dev
```
