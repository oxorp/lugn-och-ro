# API Overview

> All internal API endpoints served by the PlatsIndex application.

## Overview

API endpoints are defined in `routes/web.php` (not `routes/api.php`). They use JSON responses but share the same middleware stack as the web application, including session-based auth and rate limiting.

## Endpoint Summary

### Location Lookup (Primary API)

| Method | Path | Description |
|---|---|---|
| GET | `/api/location/{lat},{lng}` | **Per-address scoring** — blended area + proximity score, nearby schools, POIs, indicators |

### Map Data

| Method | Path | Description | Cache |
|---|---|---|---|
| GET | `/api/deso/geojson` | DeSO polygon boundaries | 24h |
| GET | `/api/deso/scores?year=2024` | Composite scores per DeSO | 1h |
| GET | `/api/pois` | POI data for map display | — |
| GET | `/api/pois/categories` | POI category definitions | — |
| GET | `/api/h3/scores` | H3 hexagonal scores | 1h |
| GET | `/api/h3/viewport?bbox=...&zoom=...` | Viewport-filtered H3 scores | 5min |
| GET | `/tiles/{year}/{z}/{x}/{y}.png` | Pre-rendered heatmap tiles | 24h |

### Tiered Endpoints (data varies by user tier)

| Method | Path | Description | Rate Limited |
|---|---|---|---|
| GET | `/api/deso/{code}/schools` | Schools in a DeSO | Yes |
| GET | `/api/deso/{code}/crime` | Crime data for a DeSO | Yes |
| GET | `/api/deso/{code}/financial` | Financial data for a DeSO | Yes |
| GET | `/api/deso/{code}/pois` | POIs near a DeSO | Yes |
| GET | `/api/deso/{code}/indicators` | All indicator values for a DeSO | Yes |

### Purchase & Reports

| Method | Path | Description |
|---|---|---|
| GET | `/purchase/{lat},{lng}` | Purchase flow page |
| POST | `/purchase/checkout` | Create Stripe Checkout session |
| GET | `/purchase/success?session_id=...` | Post-payment processing page |
| GET | `/purchase/cancel?session_id=...` | Cancelled payment redirect |
| GET | `/purchase/status/{sessionId}` | Poll payment status (JSON) |
| POST | `/stripe/webhook` | Stripe webhook (CSRF excluded) |
| GET | `/reports/{uuid}` | View completed report |
| GET | `/my-reports` | List purchased reports |
| POST | `/my-reports/request-access` | Request guest report access link |

### Authentication

| Method | Path | Description |
|---|---|---|
| GET | `/auth/google` | Google OAuth redirect |
| GET | `/auth/google/callback` | Google OAuth callback |

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
| Public | 0 | Composite score + 8 free preview indicators + category stats |
| FreeAccount | 1 | Same as Public + report purchase enabled |
| Unlocked | 2 | Full breakdown (purchased report) |
| Subscriber | 3 | Exact values + historical data for all locations |
| Enterprise | 4 | Full data + API access |
| Admin | 99 | Everything + debug info |

## Related

- [Location Lookup](/api/location-lookup)
- [DeSO GeoJSON](/api/deso-geojson)
- [DeSO Scores](/api/deso-scores)
- [DeSO Schools](/api/deso-schools)
- [DeSO Indicators](/api/deso-indicators)
- [H3 Endpoints](/api/h3-endpoints)
- [Heatmap Tiles](/api/heatmap-tiles)
- [Admin Endpoints](/api/admin-endpoints)
