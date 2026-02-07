# Troubleshooting

> Common issues and their solutions.

## Docker

### Container won't start

```bash
# Check container logs
docker compose logs app
docker compose logs postgres

# Rebuild if image changed
docker compose up -d --build
```

### PostgreSQL connection refused

The `app` container depends on `postgres` but doesn't wait for it to be fully ready. If migrations fail on first boot:

```bash
# Wait for health check, then retry
docker compose exec app php artisan migrate
```

### Permission issues on bind mounts

If files created inside the container have wrong ownership:

```bash
# Rebuild with your user/group IDs
USER_ID=$(id -u) GROUP_ID=$(id -g) docker compose up -d --build
```

## Data Ingestion

### SCB API: memory exhaustion

Large SCB responses need 1GB memory. The custom `php.ini` sets this, but if running outside Docker:

```bash
php -d memory_limit=1G artisan ingest:scb --all
```

### SCB API: rate limiting / timeouts

The SCB PX-Web API has no official rate limit but throttles heavy usage. The service uses:
- 500ms delay between calls
- 3 retries with 2-second backoff
- 120-second timeout

### Skolverket: 404 on large page sizes

Planned Educations API v3 returns 404 if page size exceeds ~100. The service uses batch size 10 by default.

### BRA: file not found

BRA has no API. Download files manually:
- Kommun CSV: Place in `storage/app/data/raw/bra/anmalda_brott_kommuner_2025.csv`
- National Excel: Place in `storage/app/data/raw/bra/anmalda_brott_10_ar.xlsx`

### Kolada: municipality filtering

The Kolada API returns regions (type "L") alongside kommuner (type "K"). Both can have 4-digit codes. The service filters by `type === 'K'` and excludes `id === '0000'` (national aggregate).

### Overpass API: timeout on large queries

Some POI categories return large datasets. The service retries on 429 (rate limit) and 504 (timeout) with 30-second backoff.

## Scoring

### Weights don't sum to 1.0

This is valid — the scoring engine divides by available weight, not total weight. But it triggers a warning in the admin dashboard.

### Sentinel check failures

Sentinel areas have expected score ranges. A failure means either:
1. Data quality issue in the source
2. Methodology change (new indicators or weight adjustments)
3. Genuine score drift (update the sentinel expected ranges)

```bash
php artisan check:sentinels --year=2024
```

### Drift detection warning

If `pipeline:run` detects large drift (>20 points on any area), it pauses before publishing. Review the drift report and either:
- Investigate the cause and fix data issues
- Accept the drift and publish manually: `php artisan scores:publish --year=2024`

## Frontend

### "Unable to locate file in Vite manifest"

The frontend assets haven't been built:

```bash
docker compose exec app npm run build
# Or for development:
docker compose exec app npm run dev
```

### Map shows gray areas

Gray areas with dashed borders indicate DeSOs without score data. Run the full pipeline to populate scores.

### H3 hexagons not loading

Check that the H3 mapping table exists:

```bash
docker compose exec app php artisan build:deso-h3-mapping --resolution=8
docker compose exec app php artisan project:scores-to-h3 --year=2024
```

## Database

### PostGIS extension missing

The custom PostgreSQL image should include PostGIS. If not:

```sql
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS h3;
```

### Slow spatial queries

Ensure spatial indexes exist on geometry columns:

```sql
-- Check indexes
SELECT indexname FROM pg_indexes WHERE tablename = 'deso_areas';
```

### Migration failures

If migrations reference PostGIS or H3 functions that don't exist yet, ensure extensions are created first. The initial migration handles this.

## Related

- [Docker Setup](/operations/docker-setup)
- [Artisan Commands](/operations/artisan-commands)
- [Data Quality](/data-pipeline/data-quality)
