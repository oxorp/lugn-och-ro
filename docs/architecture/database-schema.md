# Database Schema

> Complete schema documentation for all tables in the PlatsIndex database.

## Overview

The database is PostgreSQL 16 with PostGIS 3.4 and the h3-pg extension. Tables are organized into spatial lookup, data layer, indicator, scoring, and supporting categories.

## Core Spatial Tables

### `deso_areas`

The primary geographic unit — 6,160 DeSO statistical areas covering all of Sweden.

| Column | Type | Description |
|---|---|---|
| `id` | bigint (PK) | Auto-increment |
| `deso_code` | varchar(10), unique | DeSO identifier (e.g., `0114A0010`) |
| `deso_name` | varchar | Human-readable name |
| `kommun_code` | varchar(4) | Parent municipality code |
| `kommun_name` | varchar | Municipality name |
| `lan_code` | varchar(2) | Parent county code |
| `lan_name` | varchar | County name |
| `area_km2` | float | Area in square kilometers |
| `population` | integer | Population count |
| `urbanity_tier` | varchar | `urban`, `semi_urban`, or `rural` |
| `trend_eligible` | boolean | Whether trends can be computed (false if boundaries changed) |
| `geom` | MULTIPOLYGON (SRID 4326) | PostGIS geometry with GIST index |

### `h3_scores`

Pre-computed H3 hexagonal scores at multiple resolutions.

| Column | Type | Description |
|---|---|---|
| `h3_index` | varchar | H3 cell index |
| `resolution` | integer | H3 resolution (5–8) |
| `year` | integer | Data year |
| `score_raw` | decimal | Unsmoothed composite score |
| `score_smoothed` | decimal | Spatially smoothed score |
| `trend_1y` | decimal | 1-year score trend |
| `primary_deso_code` | varchar | DeSO with largest overlap |

### `deso_h3_mapping`

Lookup table mapping DeSO polygons to H3 hexagonal cells.

| Column | Type | Description |
|---|---|---|
| `deso_code` | varchar | FK to deso_areas |
| `h3_index` | varchar | H3 cell index |
| `area_weight` | decimal | Fraction of DeSO area in this hex |

## Indicator Tables

### `indicators`

Master table defining all scoring indicators.

| Column | Type | Description |
|---|---|---|
| `id` | bigint (PK) | Auto-increment |
| `slug` | varchar(80), unique | Machine identifier (e.g., `median_income`) |
| `name` | varchar | Display name |
| `description` | varchar | Short description |
| `source` | varchar(40) | Data source identifier |
| `source_table` | varchar | SCB table code or API path |
| `unit` | varchar(40) | Unit of measurement (SEK, %, rate) |
| `direction` | enum | `positive`, `negative`, or `neutral` |
| `weight` | decimal(5,4) | Scoring weight (0.0000–1.0000) |
| `normalization` | varchar(40) | Method: `rank_percentile`, `min_max`, `z_score` |
| `normalization_scope` | varchar | `national` or `urbanity_stratified` |
| `is_active` | boolean | Whether included in scoring |
| `display_order` | integer | UI display ordering |
| `category` | varchar | Grouping category |
| `description_short` | text | Brief explanation for users |
| `description_long` | text | Detailed explanation (tier 2+) |
| `methodology_note` | text | How the value is computed |
| `national_context` | text | Sweden-wide context |
| `source_name` | varchar | Human-readable source name |
| `source_url` | varchar | Link to data source |
| `source_api_path` | varchar | Technical API path |
| `source_field_code` | varchar | Field identifier in source API |
| `update_frequency` | varchar | How often data refreshes |
| `data_vintage` | varchar | Latest data year |
| `data_quality_notes` | text | Known quality issues |
| `admin_notes` | text | Internal notes |
| `last_ingested_at` | timestamp | Last successful ingestion |

### `indicator_values`

One row per DeSO per indicator per year — the core data table.

| Column | Type | Description |
|---|---|---|
| `id` | bigint (PK) | Auto-increment |
| `indicator_id` | bigint (FK) | References indicators |
| `deso_code` | varchar | References deso_areas |
| `year` | integer | Data year |
| `raw_value` | decimal | Original value from source |
| `normalized_value` | decimal | Normalized 0–1 value |

Unique constraint on `(indicator_id, deso_code, year)`.

### `indicator_trends`

Computed trends for each indicator per DeSO.

| Column | Type | Description |
|---|---|---|
| `indicator_id` | bigint (FK) | References indicators |
| `deso_code` | varchar | References deso_areas |
| `direction` | varchar | `improving`, `declining`, `stable`, `insufficient` |
| `percent_change` | decimal | Percentage change over period |
| `absolute_change` | decimal | Absolute value change |
| `base_year` | integer | Start of trend period |
| `end_year` | integer | End of trend period |
| `data_points` | integer | Number of years with data |
| `confidence` | decimal | Trend confidence score |

## Scoring Tables

### `composite_scores`

Final weighted composite scores per DeSO per year.

| Column | Type | Description |
|---|---|---|
| `deso_code` | varchar | References deso_areas |
| `year` | integer | Scoring year |
| `score` | decimal | Composite score 0–100 |
| `trend_1y` | decimal | 1-year change |
| `factor_scores` | json | Per-indicator contributions |
| `top_positive` | json | Top 3 positive factors |
| `top_negative` | json | Top 3 negative factors |
| `score_version_id` | bigint (FK) | References score_versions |

### `score_versions`

Version tracking for score computations.

| Column | Type | Description |
|---|---|---|
| `id` | bigint (PK) | Auto-increment |
| `year` | integer | Scoring year |
| `status` | varchar | `draft`, `published`, `rolled_back` |
| `tenant_id` | bigint (FK, nullable) | Tenant-specific version |
| `published_at` | timestamp | When published |
| `notes` | text | Release notes |

## Data Source Tables

### `schools`

School locations and metadata from Skolverket.

| Column | Type | Description |
|---|---|---|
| `school_unit_code` | varchar, unique | Skolverket identifier |
| `name` | varchar | School name |
| `type_of_schooling` | varchar | Grundskola, gymnasieskola, etc. |
| `operator_type` | varchar | Kommun, enskild (private), etc. |
| `school_forms` | json | Array of school form codes |
| `lat` / `lng` | decimal | Coordinates |
| `deso_code` | varchar | Assigned DeSO area |
| `status` | varchar | `active` or `inactive` |

### `school_statistics`

Academic performance statistics per school per year.

| Column | Type | Description |
|---|---|---|
| `school_unit_code` | varchar (FK) | References schools |
| `academic_year` | varchar | E.g., `2020/21` |
| `merit_value_17` | decimal | Average meritvärde (17 subjects) |
| `goal_achievement_pct` | decimal | % students achieving goals |
| `teacher_certification_pct` | decimal | % certified teachers |
| `student_count` | integer | Number of students |

### `crime_statistics`

Kommun-level crime rates from BRÅ.

| Column | Type | Description |
|---|---|---|
| `municipality_code` | varchar | Kommun code |
| `year` | integer | Data year |
| `crime_category` | varchar | Category slug |
| `reported_count` | integer | Number of reported crimes |
| `rate_per_100k` | decimal | Rate per 100,000 inhabitants |

### `ntu_survey_data`

Perceived safety data from National Crime Survey (NTU) at län level.

### `vulnerability_areas`

Police-designated vulnerability areas (65 total).

| Column | Type | Description |
|---|---|---|
| `name` | varchar | Area name |
| `category` | varchar | `utsatt` or `sarskilt_utsatt` |
| `police_region` | varchar | Police region |
| `assessment_year` | integer | Year of assessment |
| `geom` | MULTIPOLYGON | PostGIS geometry |

### `deso_vulnerability_mapping`

Maps DeSO areas to vulnerability zones via spatial overlap.

| Column | Type | Description |
|---|---|---|
| `deso_code` | varchar | DeSO area |
| `vulnerability_area_id` | bigint (FK) | References vulnerability_areas |
| `tier` | varchar | `utsatt` or `sarskilt_utsatt` |
| `overlap_fraction` | decimal | Fraction of DeSO in vulnerability zone |

### `kronofogden_statistics`

Kommun-level debt data from Kolada API.

| Column | Type | Description |
|---|---|---|
| `municipality_code` | varchar | Kommun code |
| `municipality_name` | varchar | Kommun name |
| `year` | integer | Data year |
| `indebted_pct` | decimal | % adults with debt at Kronofogden |
| `median_debt_sek` | decimal | Median debt in SEK |
| `eviction_rate_per_100k` | decimal | Eviction rate per 100k |

### `debt_disaggregation_results`

Estimated DeSO-level debt rates disaggregated from kommun data.

| Column | Type | Description |
|---|---|---|
| `deso_code` | varchar | DeSO area |
| `municipality_code` | varchar | Parent kommun |
| `year` | integer | Data year |
| `estimated_debt_rate` | decimal | Model-estimated debt rate |
| `estimated_eviction_rate` | decimal | Model-estimated eviction rate |
| `model_id` | bigint (FK) | References disaggregation_models |

### `pois`

All points of interest from any source.

| Column | Type | Description |
|---|---|---|
| `external_id` | varchar | Source-specific identifier |
| `source` | varchar | `osm`, `google`, etc. |
| `category` | varchar | Category slug |
| `subcategory` | varchar | Sub-category |
| `name` | varchar | POI name |
| `lat` / `lng` | decimal | Coordinates |
| `geom` | POINT (PostGIS) | Spatial index |
| `deso_code` | varchar | Assigned DeSO |
| `tags` | jsonb | Source-specific tags |
| `metadata` | jsonb | Additional metadata |
| `status` | varchar | `active`, `inactive`, `pending` |

### `poi_categories`

Category definitions for POI types.

| Column | Type | Description |
|---|---|---|
| `slug` | varchar, unique | Category identifier |
| `name` | varchar | Display name |
| `signal` | varchar | `positive`, `negative`, `neutral` |
| `icon` | varchar | Icon identifier |
| `color` | varchar | Display color |
| `indicator_slug` | varchar | Associated indicator |
| `display_tier` | integer | Minimum tier to see on map |
| `category_group` | varchar | Grouping for UI |
| `is_active` | boolean | Whether active |
| `show_on_map` | boolean | Whether shown on map layer |

## Multi-Tenancy Tables

### `tenants`

Enterprise customers with custom scoring weights.

### `tenant_indicator_weights`

Per-tenant overrides for indicator weight, direction, and active status.

### `subscriptions`

User subscription tracking.

### `user_unlocks`

Individual DeSO/kommun unlock purchases.

## Data Quality Tables

### `validation_rules` / `validation_results`

Automated data quality checks.

### `sentinel_areas`

Known-quality reference areas for validation.

### `ingestion_logs`

Audit trail for all data ingestion runs.

### `methodology_changes`

Changelog for scoring methodology updates.

### `deso_boundary_changes` / `deso_code_mappings`

Track DeSO boundary revisions (2018 → 2025) and code mappings.

## Related

- [Architecture Overview](/architecture/)
- [Indicator Pattern](/architecture/indicator-pattern)
- [Data Pipeline](/data-pipeline/)
