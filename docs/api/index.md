# API Overview

> All internal API endpoints served by the PlatsIndex application.

## Overview

API endpoints are defined in `routes/web.php` (not `routes/api.php`). They use JSON responses but share the same middleware stack as the web application, including session-based auth and rate limiting.

## Endpoint Summary

### Public Endpoints

| Method | Path | Description | Cache |
|---|---|---|---|
| GET | `/api/deso/geojson` | DeSO polygon boundaries | 24h |
| GET | `/api/deso/scores?year=2024` | Composite scores per DeSO | 1h |
| GET | `/api/pois` | POI data for map display | — |
| GET | `/api/pois/categories` | POI category definitions | — |
| GET | `/api/h3/scores` | H3 hexagonal scores | 1h |
| GET | `/api/h3/viewport?bbox=...&zoom=...` | Viewport-filtered H3 scores | 5min |
| GET | `/api/geocode/resolve-deso?q=...` | Address → DeSO lookup | — |
| POST | `/api/compare` | Compare multiple DeSOs | — |

### Tiered Endpoints (data varies by user tier)

| Method | Path | Description | Rate Limited |
|---|---|---|---|
| GET | `/api/deso/{code}/schools` | Schools in a DeSO | Yes |
| GET | `/api/deso/{code}/crime` | Crime data for a DeSO | Yes |
| GET | `/api/deso/{code}/financial` | Financial data for a DeSO | Yes |
| GET | `/api/deso/{code}/pois` | POIs near a DeSO | Yes |
| GET | `/api/deso/{code}/indicators` | All indicator values for a DeSO | Yes |

### Admin Endpoints (requires auth + admin role)

| Method | Path | Description |
|---|---|---|
| GET | `/admin/indicators` | Indicator management dashboard |
| PUT | `/admin/indicators/{id}` | Update indicator settings |
| PUT | `/admin/poi-categories/{id}` | Update POI category settings |
| POST | `/admin/recompute-scores` | Re-normalize and recompute all scores |
| GET | `/admin/data-quality` | Data quality dashboard |
| POST | `/admin/data-quality/publish/{id}` | Publish a score version |
| POST | `/admin/data-quality/rollback/{id}` | Rollback a score version |
| GET | `/admin/pipeline` | Pipeline management dashboard |
| POST | `/admin/pipeline/{source}/run` | Trigger ingestion for a source |

## Data Tiers

All tiered endpoints use the `DataTieringService` to control data granularity:

| Tier | Value | Access |
|---|---|---|
| Public | 0 | Score only, no details |
| FreeAccount | 1 | Band-level data (high/medium/low) |
| Unlocked | 2 | Approximate values |
| Subscriber | 3 | Exact values + historical data |
| Enterprise | 4 | Full data + API access |
| Admin | 99 | Everything + debug info |

## Related

- [DeSO GeoJSON](/api/deso-geojson)
- [DeSO Scores](/api/deso-scores)
- [DeSO Schools](/api/deso-schools)
- [DeSO Indicators](/api/deso-indicators)
- [H3 Endpoints](/api/h3-endpoints)
- [Admin Endpoints](/api/admin-endpoints)
