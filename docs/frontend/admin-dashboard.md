# Admin Dashboard

> Indicator management, data quality, and pipeline control.

## Admin Layout

**Layout**: `resources/js/layouts/admin-layout.tsx`

All admin pages share a common layout with:
- **Header**: Back-to-map link and "Admin" breadcrumb
- **Tab navigation**: Indicators, Pipeline, Data Quality (with active state highlighting)
- **Max width**: 3000px for wide-screen indicator tables

## Indicator Management

**Page**: `resources/js/pages/admin/indicators.tsx`
**Route**: `/admin/indicators`

### Weight Allocation

Top card shows a progress bar of total weight used (target: 1.00) with per-category breakdown across 10 categories: income, employment, education, demographics, housing, crime, safety, financial_distress, amenities, transport.

### Urbanity Distribution

Card showing DeSO counts by urbanity tier (urban, semi_urban, rural) with percentages.

### Search and Filtering

The indicator table includes a search bar to filter by name or slug, and indicators are grouped by category with collapsible sections. Categories follow a defined display order (income first, transport last).

### Indicator Table

Each indicator row shows:

| Column | Type | Description |
|---|---|---|
| Name | Text | Human-readable name |
| Slug | Code | Technical identifier |
| Source | Badge | Data source (scb, skolverket, bra, etc.) |
| Category | Colored badge | Grouping (income, employment, etc.) |
| Direction | Select | positive / negative / neutral |
| Weight | Number input | 0.00 - 1.00 |
| Normalization | Select | rank_percentile / min_max / z_score |
| Scope | Select | national / urbanity_stratified |
| Active | Toggle | Include in scoring |
| Year | Text | Latest data year |
| Coverage | Text | DeSOs with data / total DeSOs |

All changes save immediately via Inertia `router.put()` with `preserveScroll`.

### Explanation Editor

Pencil icon opens a dialog to edit:
- Short description (100 chars)
- Long description (500 chars)
- Methodology note (300 chars)
- National context (100 chars)
- Source name and URL
- Update frequency

### Recompute Button

Top-right button triggers `POST /admin/recompute-scores` to re-normalize and recompute all scores.

## POI Categories Table

Below the indicator table. Each POI category row shows:

| Column | Description |
|---|---|
| Name | Category name with color dot |
| Slug | Technical identifier |
| Signal | positive / negative / neutral |
| Group | Category group |
| Tier | Display zoom tier (1-5, where 1 = zoom 8+, 5 = zoom 16+) |
| POIs | Count of ingested POIs |
| Indicator | Linked indicator slug (if any) |
| Scoring | Toggle: include in score computation |
| Map | Toggle: show on map |

## Data Quality Dashboard

**Page**: `resources/js/pages/admin/data-quality.tsx`
**Route**: `/admin/data-quality`

Shows validation results, drift detection reports, and version management (publish/rollback).

## Pipeline Dashboard

**Page**: `resources/js/pages/admin/pipeline.tsx`
**Route**: `/admin/pipeline`

Pipeline overview with per-source status.

**Source Detail**: `resources/js/pages/admin/pipeline-source.tsx`
**Route**: `/admin/pipeline/{source}`

Individual source ingestion history with log viewing and manual trigger.

## View-As (Tier Simulation)

Admins can simulate viewing the application as a different tier:

```
POST /admin/view-as   { "tier": 2 }  → View as Unlocked user
DELETE /admin/view-as                  → Clear simulation
```

This affects all tier-gated API responses and sidebar content.

## Related

- [Admin Endpoints](/api/admin-endpoints)
- [Indicator Pattern](/architecture/indicator-pattern)
- [Scoring Engine](/architecture/scoring-engine)
