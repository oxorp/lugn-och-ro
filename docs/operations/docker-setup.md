# Docker Setup

> Five-container architecture with PostGIS and H3 extensions.

## Containers

| Container | Image | Purpose | Ports |
|---|---|---|---|
| `skapa-app` | Custom PHP 8.4 | Laravel application, Artisan, Node.js | — |
| `skapa-nginx` | `nginx:alpine` | HTTP reverse proxy | `80` (configurable via `APP_PORT`) |
| `skapa-postgres` | Custom PostGIS + H3 | Database with spatial extensions | `5432` (configurable via `DB_PORT`) |
| `skapa-redis` | `redis:alpine` | Cache and queue backend | `6379` (configurable via `REDIS_PORT`) |
| `skapa-horizon` | Same as app | Laravel Horizon queue worker | — |

## Network

All containers share the `skapa-network` bridge network. Service names (`postgres`, `redis`) resolve internally.

## Volumes

| Volume | Purpose |
|---|---|
| `skapa-postgres-data` | Persistent database storage |
| `skapa-redis-data` | Redis persistence |
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
```

## Health Checks

Both `postgres` and `redis` have health checks configured:

- **PostgreSQL**: `pg_isready` every 10s, 5 retries
- **Redis**: `redis-cli ping` every 10s, 5 retries

The `app` and `horizon` containers wait for these dependencies via `depends_on`.

## Related

- [Running Locally](/operations/running-locally)
- [Architecture Stack](/architecture/stack)
