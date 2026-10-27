# Marker Wrapper

A reusable PHP gateway for XYZ tiles, static map images and Maki markers.
It works with MediaWiki Kartographer and other clients; the service is not
tied to a specific wiki or domain.

The gateway restricts requests to supported image routes and an operator-
configured tile provider, with persistent caching.

## Run

1. Configure `TILE_SERVER` (an authorized HTTPS XYZ provider) and
   `MARKER_USER_AGENT` (a service identifier with contact information)
   in a local `.env` file or your deployment environment. Optional
   settings are documented directly in `docker-compose.yml`.
2. Start the service:

   ```sh
   git submodule update --init --recursive
   docker compose up -d --build
   ```

The default Compose file publishes no host ports: connect Nginx to your
existing reverse proxy network. See [development instructions](docs/development.md)
for local access, tests and operations.

**Note:** static images contain base tiles only; GeoJSON overlays are
rendered by the interactive client.
