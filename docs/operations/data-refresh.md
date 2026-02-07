# Data Refresh

> Pipeline scheduling and annual refresh workflow.

## Annual Refresh Cycle

Most data sources update annually. The typical refresh window is January-March when prior-year data becomes available.

### Source Update Schedule

| Source | Typical Availability | Frequency |
|---|---|---|
| SCB Demographics | February-March | Annual |
| Skolverket Schools | October (registry), Spring (stats) | Annual |
| BRA Crime CSV | March-April | Annual |
| NTU Survey | January-February | Annual |
| Police Vulnerability | Every 2 years | Biennial |
| Kronofogden/Kolada | March-April | Annual |
| POIs (OSM) | Real-time | Quarterly recommended |

## Full Pipeline Run

The recommended approach is the `pipeline:run` command which executes all stages in order:

```bash
php artisan pipeline:run --year=2024 --auto-publish
```

### Pipeline Stages

1. **Ingest** — Fetch from all external sources
2. **Validate** — Run data quality rules on ingestion logs
3. **Aggregate & Disaggregate** — School aggregation, crime/debt disaggregation
4. **Normalize** — Percentile rank computation
5. **Score** — Weighted composite scoring
6. **Trends** — Year-over-year direction computation
7. **Sentinel Checks** — Verify known areas match expected ranges
8. **Drift Analysis** — Compare against previously published version
9. **Publish** — Make new scores live (auto or manual)

### Manual Step-by-Step

If you need granular control:

```bash
# 1. Ingest each source
php artisan ingest:scb --all --year=2024
php artisan ingest:skolverket-schools
php artisan ingest:skolverket-stats
php artisan ingest:bra-crime --year=2024
php artisan ingest:ntu --year=2025
php artisan ingest:kronofogden --year=2024 --source=kolada
php artisan ingest:pois --all

# 2. Aggregate and disaggregate
php artisan aggregate:school-indicators --academic-year=2020/21 --calendar-year=2024
php artisan aggregate:poi-indicators --year=2024 --sync
php artisan disaggregate:crime --year=2024
php artisan disaggregate:kronofogden --year=2024
php artisan aggregate:kronofogden-indicators --year=2024

# 3. Normalize and score
php artisan normalize:indicators --year=2024
php artisan compute:scores --year=2024

# 4. Quality checks
php artisan check:sentinels --year=2024
php artisan check:freshness

# 5. H3 projection (if needed)
php artisan project:scores-to-h3 --year=2024
php artisan smooth:h3-scores --year=2024 --config=Light

# 6. Publish
php artisan scores:publish --year=2024
```

## POI Refresh

POI data should be refreshed more frequently than annual indicators:

```bash
# Quarterly OSM refresh
php artisan ingest:pois --all
php artisan assign:poi-deso
php artisan aggregate:poi-indicators --year=2024 --sync
```

## Freshness Monitoring

```bash
php artisan check:freshness
```

Returns exit code 1 if any indicator is outdated. Freshness thresholds:
- SCB, Skolverket, Kronofogden: stale at 15 months, outdated at 24 months
- BRA: stale at 6 months, outdated at 12 months

## Score Versioning

Each `compute:scores` run creates a new `ScoreVersion` record with status `pending`. Versions flow through:

```
pending → published → superseded (when next version publishes)
                    → rolled_back (if issues detected)
```

Only one version per year can be `published` at a time.

## Rollback

If a published version has issues:

```bash
php artisan scores:rollback --to-version=<id> --reason="description"
```

## Related

- [Artisan Commands](/operations/artisan-commands)
- [Data Quality](/data-pipeline/data-quality)
- [Scoring Pipeline](/data-pipeline/scoring)
