# Admin Dashboard

> Indicator management, data quality, and pipeline control.

## Admin Layout

**Layout**: `resources/js/layouts/admin-layout.tsx`

All admin pages share a common layout with:
- **Header**: Back-to-map link and "Admin" breadcrumb
- **Tab navigation**: Indicators, POI Categories, Pipeline, Data Quality (with active state highlighting)
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

## POI Categories Dashboard

**Page**: `resources/js/pages/admin/poi-categories.tsx`
**Route**: `/admin/poi-categories`
**Controller**: `AdminPoiCategoryController`

A dedicated admin page for managing POI categories and their safety sensitivity settings.

### Category Table

Each POI category row shows:

| Column | Type | Description |
|---|---|---|
| Name | Text + color dot | Category name |
| Slug | Code | Technical identifier |
| Signal | Badge | positive (green) / negative (red) / neutral (gray) |
| Safety Sensitivity | Number input | 0.0–1.5 modulation strength |
| Catchment | Text | Radius in km |
| POIs | Count | Active POI count for this category |
| Active | Toggle | Enable/disable category |

Changes save via `PUT /admin/poi-categories/{id}/safety`.

### Safety Modulation Preview

Two example panels show the effect of safety sensitivity at 500m physical distance:

1. **Safe area** (safety = 0.90) — Shows effective distances (close to physical)
2. **Unsafe area** (safety = 0.15) — Shows inflated effective distances

This helps admins understand the impact of sensitivity values before saving.

### POI Categories on Indicators Page

The indicators admin page also shows a simplified POI category table below the indicator table with: name, slug, signal, group, tier, POI count, linked indicator, scoring toggle, map toggle.

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
