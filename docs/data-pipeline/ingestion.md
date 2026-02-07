# Ingestion

> How raw data is fetched from external sources and stored in the database.

## Overview

Each data source has a dedicated Artisan command that handles fetching, parsing, and storing data. All ingestion commands log their activity to the `ingestion_logs` table for audit and monitoring.

## SCB Demographics (`ingest:scb`)

**Service**: `app/Services/ScbApiService.php`

Fetches demographic indicators from the SCB PX-Web API using POST requests with JSON query bodies. Responses are in JSON-stat2 format.

```bash
php artisan ingest:scb --all --year=2024
php artisan ingest:scb --indicator=median_income --year=2024
```

### How It Works

1. For each configured indicator, builds a PX-Web query with region=all DeSOs, year, and the target variable
2. POSTs to `https://api.scb.se/OV0104/v1/doris/sv/ssd/{table_path}`
3. Parses JSON-stat2 response to extract DeSO code → value pairs
4. Strips `_DeSO2025` suffix from DeSO codes using `extractDesoCode()`
5. Upserts into `indicator_values` in chunks of 1,000

### Key Gotchas

- **DeSO 2025 suffix**: API responses append `_DeSO2025` to codes — must strip
- **Memory**: Large responses need `memory_limit=1G`
- **Employment data**: `AM0207` table only goes to 2021 with old DeSO codes (5,835 matched). All other indicators have 2024 data with DeSO 2025 codes (6,160 matched).
- **Rate limiting**: SCB has no published hard limits but requests should be reasonable

### Indicators Ingested

| Indicator Slug | SCB Table | Description |
|---|---|---|
| `median_income` | HE0110 | Median disposable income (SEK) |
| `low_economic_standard_pct` | HE0110 | % below low economic standard |
| `employment_rate` | AM0207 | Employment rate (%) |
| `education_post_secondary_pct` | UF0506 | % with post-secondary education |
| `education_below_secondary_pct` | UF0506 | % without secondary education |
| `foreign_background_pct` | BE0101 | % foreign background |
| `social_assistance_pct` | SO0204 | % receiving social assistance |
| `population` | BE0101 | Total population count |

## Skolverket Schools (`ingest:skolverket-schools`)

**Service**: `app/Services/SkolverketApiService.php`

Fetches the school registry from Skolverket's Skolenhetsregistret v2 API.

```bash
php artisan ingest:skolverket-schools
```

### How It Works

1. Fetches paginated school unit list from the registry API
2. For each school: extracts code, name, type, operator, coordinates
3. Assigns `deso_code` by spatial lookup (point-in-polygon via PostGIS)
4. Upserts into `schools` table

## Skolverket Statistics (`ingest:skolverket-stats`)

Fetches academic performance statistics from the Planned Educations v3 API.

```bash
php artisan ingest:skolverket-stats
```

### Key Gotchas

- **Max page size**: The v3 API returns 404 on `size=500`; use ~100
- **Accept header**: Must send `application/vnd.skolverket.plannededucations.api.v3.hal+json`
- **Swedish decimals**: Statistics use comma as decimal separator — convert with `str_replace(',', '.', $value)`
- **Value validation**: Only use values where `valueType === 'EXISTS'`; skip `.` and `..` placeholders
- **Data coverage**: Most school-level stats are from 2020/21 (Skolverket restricted publication after that)
- **HTTP pool errors**: `Http::pool()` returns `ConnectionException` for failed requests — always check `instanceof Response` before calling `->successful()`

## BRÅ Crime Data (`ingest:bra-crime`)

**Service**: `app/Services/BraDataService.php`

Ingests crime statistics from local CSV/Excel files (BRÅ has no public API).

```bash
php artisan ingest:bra-crime --year=2024
```

### Data Files

| File | Content |
|---|---|
| `storage/app/data/raw/bra/anmalda_brott_kommuner_2025.csv` | 290 kommuner, total crimes + rate per 100k |
| `storage/app/data/raw/bra/anmalda_brott_10_ar.xlsx` | Crime categories by year, national level |

### How It Works

1. Parses kommun-level CSV for total crime counts and rates
2. Parses national Excel for crime category breakdowns
3. Estimates kommun-level category rates by applying national proportions to kommun totals
4. Stores in `crime_statistics` table

### Key Gotchas

- **Swedish formatting**: `..` = suppressed data, `-` = zero, comma decimals, BOM in CSV
- **No API**: All data must be manually downloaded from bra.se

## NTU Survey (`ingest:ntu`)

Ingests perceived safety data from the National Crime Survey Excel file.

```bash
php artisan ingest:ntu --year=2025
```

### Data File

`storage/app/data/raw/bra/ntu_lan_2017_2025.xlsx` — 21 län, years 2017–2025.

Key metric: "Otrygghet vid utevistelse sent på kvällen" (% feeling unsafe at night).

## Police Vulnerability Areas (`ingest:vulnerability-areas`)

Imports GeoJSON polygons for the 65 police-designated vulnerability areas.

```bash
php artisan ingest:vulnerability-areas --year=2025
```

### Key Gotchas

- **CRS**: GeoJSON uses EPSG:3006 (SWEREF99TM) — must transform to WGS84 via PostGIS `ST_Transform`
- **Geometry type**: Source is Polygon — wrap with `ST_Multi` for MULTIPOLYGON storage
- **Categories**: 46 "utsatt" + 19 "särskilt utsatt" = 65 total areas
- **DeSO overlap**: ~275 DeSOs have ≥25% overlap with vulnerability areas

## Kronofogden Debt (`ingest:kronofogden`)

**Service**: `app/Services/KronofogdenService.php`

Fetches debt statistics from the Kolada API (not directly from Kronofogden).

```bash
php artisan ingest:kronofogden --year=2024 --source=kolada
```

### Key Gotchas

- **Kolada URL format**: `/data/kpi/{kpiId}/year/{year}` — NOT `/municipality/all/year/`
- **KPIs**: `N00989` (debt rate %), `N00990` (median debt SEK), `U00958` (eviction rate per 100k)
- **Region filtering**: Municipality list includes region codes (type "L") — filter on `type === 'K'` and exclude `id === '0000'` (Riket)
- **Gender filtering**: Response values have `gender` field — use `T` for total (not M/K)

## POI Data (`ingest:pois`)

**Service**: `app/Services/OverpassService.php`

Queries OpenStreetMap via the Overpass API for points of interest.

```bash
php artisan ingest:pois
```

### How It Works

1. Reads active categories from `poi_categories` table
2. For each category, builds an Overpass QL query from `osm_tags`
3. POSTs query to `https://overpass-api.de/api/interpreter`
4. Assigns DeSO codes via PostGIS point-in-polygon
5. Upserts into `pois` table

## Ingestion Logging

All ingestion commands create `IngestionLog` records:

| Field | Description |
|---|---|
| `source` | Source identifier |
| `started_at` | When ingestion began |
| `completed_at` | When ingestion finished |
| `status` | `success`, `failed`, `partial` |
| `records_processed` | Number of records handled |
| `error_message` | Error details if failed |

## Related

- [Data Pipeline Overview](/data-pipeline/)
- [Normalization](/data-pipeline/normalization)
- [Artisan Commands](/operations/artisan-commands)
- [Data Sources](/data-sources/)
