# Admin Endpoints

> Protected endpoints for indicator management, scoring, and pipeline control.

## Authentication

All admin endpoints require:
- Authenticated user (`auth` middleware)
- Admin role (`admin` middleware, checks `users.is_admin`)

## Indicator Management

### `GET /admin/indicators`

Inertia page showing all indicators with weights, coverage, and POI categories.

**Controller**: `AdminIndicatorController@index`

### `PUT /admin/indicators/{id}`

Update indicator weight, direction, normalization, descriptions, and active status.

**Controller**: `AdminIndicatorController@update`

Supports multi-tenant: updates `tenant_indicator_weights` if a tenant context is active.

### `PUT /admin/poi-categories/{id}`

Update POI category settings (active, show_on_map, display_tier, signal).

**Controller**: `AdminIndicatorController@updatePoiCategory`

## Score Management

### `POST /admin/recompute-scores`

Triggers re-normalization and recomputation of all scores.

**Controller**: `AdminScoreController@recompute`

### Data Quality Dashboard

| Endpoint | Method | Description |
|---|---|---|
| `/admin/data-quality` | GET | Dashboard with validation results, drift detection |
| `/admin/data-quality/publish/{id}` | POST | Publish a draft score version |
| `/admin/data-quality/rollback/{id}` | POST | Roll back to previous version |

### Pipeline Management

| Endpoint | Method | Description |
|---|---|---|
| `/admin/pipeline` | GET | Pipeline overview dashboard |
| `/admin/pipeline/{source}` | GET | Source-specific pipeline detail |
| `/admin/pipeline/{source}/run` | POST | Trigger ingestion for a source |
| `/admin/pipeline/run-all` | POST | Run full pipeline |
| `/admin/pipeline/logs/{id}` | GET | View ingestion log details |

## View-As (Tier Simulation)

Admins can simulate different user tiers:

```
POST /admin/view-as   { "tier": 2 }  → View as Unlocked user
DELETE /admin/view-as                  → Clear simulation
```

## Related

- [API Overview](/api/)
- [Admin Dashboard](/frontend/admin-dashboard)
