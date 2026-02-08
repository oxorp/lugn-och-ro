# Artisan Commands

> Complete reference for all custom Artisan commands.

## Data Ingestion

### `ingest:scb`

Ingest demographic data from SCB PX-Web API.

```bash
php artisan ingest:scb --all                    # All SCB indicators
php artisan ingest:scb --indicator=median_income # Single indicator
php artisan ingest:scb --indicator=median_income --year=2024
php artisan ingest:scb --from=2020 --to=2024    # Year range
```

| Option | Description |
|---|---|
| `--indicator=` | Specific indicator slug |
| `--year=` | Specific year |
| `--from=` / `--to=` | Year range |
| `--all` | All configured SCB indicators |

**Service**: `ScbApiService` — 500ms rate limiting between API calls, 3 retries with exponential backoff.

### `ingest:skolverket-schools`

Ingest school units from Skolverket Registry API v2.

```bash
php artisan ingest:skolverket-schools
php artisan ingest:skolverket-schools --skip-details  # List only, no geocoding
php artisan ingest:skolverket-schools --include-ceased # Include closed schools
```

| Option | Default | Description |
|---|---|---|
| `--delay=` | 100 | Milliseconds between batch requests |
| `--batch-size=` | 10 | Concurrent HTTP pool size |
| `--force` | false | Re-fetch existing schools |
| `--skip-details` | false | Skip individual detail fetches |
| `--include-ceased` | false | Include ceased schools |

Uses HTTP pool for concurrent requests. Performs PostGIS spatial join to assign DeSO codes.

### `ingest:skolverket-stats`

Ingest school performance statistics from Planned Educations API v3.

```bash
php artisan ingest:skolverket-stats
php artisan ingest:skolverket-stats --limit=100  # Test with subset
```

| Option | Default | Description |
|---|---|---|
| `--delay=` | 200 | Milliseconds between batches |
| `--batch-size=` | 10 | Concurrent pool size |
| `--limit=` | 0 (all) | Max schools to process |

Requires specific Accept header. Handles Swedish decimal format (comma separator).

### `ingest:bra-crime`

Ingest BRA reported crime statistics from CSV/Excel files.

```bash
php artisan ingest:bra-crime --year=2024
php artisan ingest:bra-crime --file=/path/to/custom.csv
```

| Option | Default | Description |
|---|---|---|
| `--year=` | 2024 | Data year |
| `--file=` | auto | Custom kommun CSV path |
| `--national-file=` | auto | Custom national Excel path |

No API available — reads from `storage/app/data/raw/bra/`.

### `ingest:ntu`

Ingest NTU (National Safety Survey) data from Excel.

```bash
php artisan ingest:ntu --year=2025
```

| Option | Default | Description |
|---|---|---|
| `--year=` | 2025 | Survey year |
| `--file=` | auto | Custom Excel path |

Reads lan-level survey data from configured sheets (R4.1, R4.2, R4.6, R4.11).

### `ingest:vulnerability-areas`

Import police vulnerability area polygons from GeoJSON.

```bash
php artisan ingest:vulnerability-areas --year=2025
```

| Option | Default | Description |
|---|---|---|
| `--year=` | 2025 | Assessment year |
| `--file=` | auto | Custom GeoJSON path (downloads from Polisen if not set) |

Handles CRS transformation (EPSG:3006 to WGS84). Computes DeSO overlap fractions.

### `ingest:kronofogden`

Ingest financial distress data from Kolada API.

```bash
php artisan ingest:kronofogden --year=2024 --source=kolada
```

| Option | Default | Description |
|---|---|---|
| `--year=` | 2024 | Data year |
| `--source=` | kolada | Data source |

Fetches debt rates, median debt, and eviction rates for all 290 kommuner.

### `ingest:pois`

Ingest POI data from OpenStreetMap via Overpass API.

```bash
php artisan ingest:pois --all                 # All active categories
php artisan ingest:pois --category=grocery    # Single category
php artisan ingest:pois --source=osm          # Explicit source
```

| Option | Default | Description |
|---|---|---|
| `--source=` | osm | Data source (osm or google) |
| `--category=` | — | Specific category slug |
| `--all` | false | All active categories |

10-second rate limiting between categories. Updates PostGIS geometry and marks stale POIs.

## Aggregation & Processing

### `aggregate:school-indicators`

Aggregate school statistics to DeSO-level indicators (student-weighted averages).

```bash
php artisan aggregate:school-indicators --academic-year=2020/21 --calendar-year=2024
```

Creates indicators: `school_merit_value_avg`, `school_goal_achievement_avg`, `school_teacher_certification_avg`.

### `aggregate:poi-indicators`

Aggregate POI data to DeSO-level catchment-based density indicators.

```bash
php artisan aggregate:poi-indicators --year=2024 --sync   # Immediate
php artisan aggregate:poi-indicators --year=2024           # Queued via Horizon
```

| Option | Description |
|---|---|
| `--year=` | Data year |
| `--category=` | Specific POI category |
| `--sync` | Run immediately instead of queuing |

### `aggregate:kronofogden-indicators`

Create indicator values from Kronofogden disaggregation results.

```bash
php artisan aggregate:kronofogden-indicators --year=2024
```

Maps disaggregation results to `debt_rate_pct`, `eviction_rate`, and `median_debt_sek` indicators.

## Disaggregation

### `disaggregate:crime`

Disaggregate kommun-level crime rates to DeSO using demographic-weighted model.

```bash
php artisan disaggregate:crime --year=2024
```

Uses propensity weights: income, employment, education, vulnerability area overlap.

### `disaggregate:kronofogden`

Disaggregate kommun-level debt data to DeSO level.

```bash
php artisan disaggregate:kronofogden --year=2024
php artisan disaggregate:kronofogden --year=2024 --validate  # With cross-validation
```

Two-pass algorithm: raw estimates (clamped 10%-300%) then population-weighted constraint. R² ~ 0.40.

## Normalization & Scoring

### `normalize:indicators`

Compute normalized values (percentile rank) for all active indicators.

```bash
php artisan normalize:indicators --year=2024
```

Routes to national or urbanity-stratified normalization based on indicator configuration.

### `compute:scores`

Compute composite neighborhood scores from normalized indicators.

```bash
php artisan compute:scores --year=2024
php artisan compute:scores --year=2024 --tenant=acme     # Tenant-specific
php artisan compute:scores --year=2024 --all-tenants     # All tenants
```

Automatically projects to H3 grid and applies smoothing if mapping table exists.

### `compute:trends`

Compute per-indicator trends for trend-eligible DeSO areas.

```bash
php artisan compute:trends --base-year=2021 --end-year=2024
```

Classifies direction: rising, falling, stable (threshold: 3% change). Requires 2+ data points.

## Pipeline & Publication

### `pipeline:run`

Run the full data pipeline end-to-end.

```bash
php artisan pipeline:run --year=2024                    # Full pipeline
php artisan pipeline:run --source=scb --year=2024       # Single source
php artisan pipeline:run --year=2024 --auto-publish     # Auto-publish if clean
```

**Stages**: Ingest → Validate → Aggregate/Disaggregate → Normalize → Score → Trends → Sentinel checks → Drift analysis → Publish.

### `scores:publish`

Publish a validated score version.

```bash
php artisan scores:publish --year=2024
php artisan scores:publish --score-version=42
```

### `scores:rollback`

Roll back to a previous score version.

```bash
php artisan scores:rollback --to-version=41 --reason="drift detected"
```

## H3 Hexagonal Grid

### `build:deso-h3-mapping`

Build DeSO-to-H3 mapping table using PostGIS.

```bash
php artisan build:deso-h3-mapping --resolution=8
```

### `project:scores-to-h3`

Project composite scores onto H3 hexagonal cells.

```bash
php artisan project:scores-to-h3 --year=2024 --resolution=8
```

### `smooth:h3-scores`

Apply spatial smoothing to H3 scores.

```bash
php artisan smooth:h3-scores --year=2024 --config=Light
```

Configs: None, Light, Medium, Strong. Pre-aggregates lower resolutions for viewport queries.

## Geographic

### `import:deso-areas`

Download and import DeSO boundary data from SCB into PostGIS.

```bash
php artisan import:deso-areas          # Download and import
php artisan import:deso-areas --fresh  # Truncate and reimport
php artisan import:deso-areas --cache-only  # Use cached file only
```

Generates static `public/data/deso.geojson` for the frontend.

### `import:deso-changes`

Detect DeSO 2018-to-2025 boundary changes and update trend eligibility.

```bash
php artisan import:deso-changes
php artisan import:deso-changes --dry-run
```

### `classify:deso-urbanity`

Classify DeSO areas by urbanity tier (urban/semi_urban/rural).

```bash
php artisan classify:deso-urbanity --method=density
```

Thresholds: urban > 1500/km², semi_urban > 100/km², rural <= 100/km².

## Utilities

### `geocode:schools`

Geocode schools missing coordinates via Nominatim.

```bash
php artisan geocode:schools --source=nominatim --delay=1100
```

### `assign:poi-deso`

Assign DeSO codes to POIs via PostGIS spatial join.

```bash
php artisan assign:poi-deso
php artisan assign:poi-deso --force  # Re-assign all
```

### `check:freshness`

Check data freshness for all indicators.

```bash
php artisan check:freshness
```

Source-specific thresholds: SCB/Skolverket/Kronofogden (15/24 months), BRA (6/12 months).

### `check:sentinels`

Verify sentinel area scores are within expected ranges.

```bash
php artisan check:sentinels --year=2024
```

## User Management

### `user:subscribe`

Activate a test subscription.

```bash
php artisan user:subscribe user@example.com --plan=monthly
```

Plans: monthly (349 SEK), annual (2990 SEK).

### `user:tier`

Check a user's data access tier.

```bash
php artisan user:tier user@example.com --deso=0114A0010
```

### `user:unlock`

Grant an area unlock for testing.

```bash
php artisan user:unlock user@example.com --deso=0114A0010
php artisan user:unlock user@example.com --kommun=0114
php artisan user:unlock user@example.com --lan=01
```

### `generate:sweden-boundary`

Generate simplified Sweden boundary GeoJSON for map mask overlay.

```bash
php artisan generate:sweden-boundary
php artisan generate:sweden-boundary --tolerance=0.005
```

| Option | Default | Description |
|---|---|---|
| `--tolerance=` | 0.005 | Simplification tolerance in degrees |

Unions all DeSO geometries and simplifies into a single boundary polygon. Saves to `public/data/sweden-boundary.geojson`.

### `generate:heatmap-tiles`

Generate pre-rendered heatmap PNG tiles from H3 scores.

```bash
php artisan generate:heatmap-tiles --year=2025
php artisan generate:heatmap-tiles --year=2025 --zoom-min=5 --zoom-max=12
```

| Option | Default | Description |
|---|---|---|
| `--year=` | 2024 | Score year to render |
| `--zoom-min=` | 5 | Minimum zoom level |
| `--zoom-max=` | 12 | Maximum zoom level |

Wraps the Python script `scripts/generate_heatmap_tiles.py`. Reads H3 scores from database, renders colored hexagons with Gaussian blur, and saves as XYZ tiles to `storage/app/public/tiles/{year}/`. Requires Python 3 with `h3`, `numpy`, `Pillow`, and `psycopg2` packages. Timeout: 30 minutes.

## Maintenance

### `purchase:cleanup`

Expire abandoned checkout sessions.

```bash
php artisan purchase:cleanup
```

Updates `reports` with `status = 'pending'` and `created_at > 2 hours ago` to `expired`. Typically scheduled daily via `routes/console.php`.

## Related

- [Data Refresh](/operations/data-refresh)
- [Data Pipeline](/data-pipeline/)
- [Troubleshooting](/operations/troubleshooting)
