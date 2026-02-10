# Docker Setup

> Six-container architecture with PostGIS, H3, and Valhalla routing engine.

## Containers

| Container | Image | Purpose | Ports |
|---|---|---|---|
| `skapa-app` | Custom PHP 8.4 | Laravel application, Artisan, Node.js, Python 3 | — |
| `skapa-nginx` | `nginx:alpine` | HTTP reverse proxy | `80` (configurable via `APP_PORT`) |
| `skapa-postgres` | Custom PostGIS + H3 | Database with spatial extensions | `5432` (configurable via `DB_PORT`) |
| `skapa-redis` | `redis:alpine` | Cache and queue backend | `6379` (configurable via `REDIS_PORT`) |
| `skapa-horizon` | Same as app | Laravel Horizon queue worker | — |
| `skapa-valhalla` | `ghcr.io/gis-ops/docker-valhalla` | Valhalla routing engine for isochrones | `8002` |

## Network

All containers share the `skapa-network` bridge network. Service names (`postgres`, `redis`) resolve internally.

## Volumes

| Volume | Purpose |
|---|---|
| `skapa-postgres-data` | Persistent database storage |
| `skapa-redis-data` | Redis persistence |
| `skapa-valhalla-data` | Valhalla routing tiles (built from Sweden OSM on first start) |
| `./` → `/var/www` | Application code (bind mount) |

## Custom Images

### PHP (`docker/php/Dockerfile`)

Based on PHP 8.4 with extensions:
- `pdo_pgsql` — PostgreSQL driver
- `gd`, `intl`, `zip`, `bcmath` — Application requirements
- Node.js 22 — Frontend build tooling
- Composer — PHP dependency management
- Custom `php.ini` for `memory_limit=1G` (required for large SCB API responses)

### PostgreSQL (`docker/postgres/Dockerfile`)

Based on PostGIS 3.4 with:
- **PostGIS** — Spatial queries (`ST_Contains`, `ST_Transform`, `ST_Intersects`)
- **H3 extension** — Hexagonal grid functions (`h3_polygon_to_cells`, `h3_grid_disk`)

## Environment Variables

Key variables in `.env`:

```env
APP_PORT=80
DB_DATABASE=realestate
DB_USERNAME=realestate
DB_PASSWORD=secret
DB_PORT=5432
REDIS_PORT=6379
VALHALLA_URL=http://valhalla:8002
ISOCHRONE_ENABLED=true
```

## Health Checks

All key containers have health checks:

- **PostgreSQL**: `pg_isready` every 10s, 5 retries
- **Redis**: `redis-cli ping` every 10s, 5 retries
- **Valhalla**: `curl http://localhost:8002/status` every 30s

The `app` and `horizon` containers wait for dependencies via `depends_on`.

## Valhalla Routing Engine

The Valhalla container provides walking/driving-time isochrone computation for the proximity scoring system.

**First start**: Downloads Sweden OSM data from Geofabrik (~1.5 GB) and builds routing tiles. This takes **10–20 minutes** on first startup. Subsequent starts are fast (tiles are cached in the `skapa-valhalla-data` volume).

**Endpoints used by the application**:
- `/isochrone` — Walking/driving time contour polygons
- `/sources_to_targets` — Travel time matrix for multiple targets

**Config**: `build_elevation=False`, `build_admins=False`, `build_time_zones=False` to minimize build time. Only Sweden data is loaded.

**Fallback**: If Valhalla is unavailable, the `ProximityScoreService` automatically falls back to radius-based scoring. No user-facing errors.

## Related

- [Running Locally](/operations/running-locally)
- [Architecture Stack](/architecture/stack)
