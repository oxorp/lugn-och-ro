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

### `deso_areas_2018`

Historical DeSO 2018 boundary geometries from SCB WFS, used for the crosswalk.

| Column | Type | Description |
|---|---|---|
| `id` | bigint (PK) | Auto-increment |
| `deso_code` | varchar(10), unique | DeSO 2018 identifier |
| `deso_name` | varchar | Human-readable name |
| `kommun_code` | varchar(4) | Parent municipality code |
| `kommun_name` | varchar | Municipality name |
| `geom` | MULTIPOLYGON (SRID 4326) | PostGIS geometry with GIST index |

### `deso_crosswalk`

Area-weighted mapping between DeSO 2018 codes and DeSO 2025 codes. Built by `build:deso-crosswalk` using spatial overlap computation.

| Column | Type | Description |
|---|---|---|
| `id` | bigint (PK) | Auto-increment |
| `old_code` | varchar | DeSO 2018 code |
| `new_code` | varchar | DeSO 2025 code |
| `overlap_fraction` | decimal | Fraction of old area mapping to this new area |
| `reverse_fraction` | decimal | Fraction of new area coming from this old area |
| `mapping_type` | varchar | `1:1`, `split`, `merge`, or `partial` |

Unique constraint on `(old_code, new_code)`. ~90% of mappings are `1:1` (unchanged areas).

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
| `category` | varchar | Display category (`safety`, `economy`, `education`, `environment`, `proximity`, `contextual`) |
| `is_free_preview` | boolean | Shown to free tier users (8 indicators) |
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
| `score` | decimal | Composite score 0–100 (after penalties) |
| `raw_score_before_penalties` | decimal, nullable | Score before penalty deductions (null if no penalties) |
| `penalties_applied` | json, nullable | Array of applied penalties `[{slug, name, amount}]` |
| `trend_1y` | decimal | 1-year change |
| `factor_scores` | json | Per-indicator contributions |
| `top_positive` | json | Top 3 positive factors |
| `top_negative` | json | Top 3 negative factors |
| `score_version_id` | bigint (FK) | References score_versions |

### `score_penalties`

Configurable post-calculation score deductions (e.g., vulnerability area penalties).

| Column | Type | Description |
|---|---|---|
| `id` | bigint (PK) | Auto-increment |
| `slug` | varchar, unique | Machine identifier (e.g., `vuln_sarskilt_utsatt`) |
| `name` | varchar | Display name |
| `description` | text | Detailed explanation |
| `category` | varchar | Penalty grouping (e.g., `vulnerability`) |
| `penalty_type` | varchar | `absolute` or `percentage` |
| `penalty_value` | decimal | Deduction amount (always ≤ 0, range -50 to 0) |
| `is_active` | boolean | Toggle without deletion |
| `applies_to` | varchar | Scope: `composite_score` |
| `display_order` | integer | UI ordering within category |
| `color` | varchar(7), nullable | Map polygon fill color (hex) |
| `border_color` | varchar(7), nullable | Map polygon border color (hex) |
| `opacity` | decimal, nullable | Map polygon opacity (0–1) |
| `metadata` | json, nullable | Extensible metadata |

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

### `transit_stops`

Authoritative transit stop data from GTFS Sverige 2 (Samtrafiken).

| Column | Type | Description |
|---|---|---|
| `id` | bigint (PK) | Auto-increment |
| `gtfs_stop_id` | varchar(30), unique | GTFS stop identifier |
| `name` | varchar, nullable | Stop name |
| `lat` / `lng` | decimal(10,7) | Coordinates |
| `parent_station` | varchar(30), nullable | Parent station grouping |
| `location_type` | tinyint | 0 = stop, 1 = station |
| `source` | varchar(20) | Default `gtfs` |
| `stop_type` | varchar(20), nullable | Dominant mode: `bus`, `rail`, `subway`, `tram`, `ferry`, `on_demand` |
| `weekly_departures` | integer, nullable | Estimated weekly departures (weekday × 5) |
| `routes_count` | integer, nullable | Distinct routes at stop |
| `deso_code` | varchar(10), nullable | Assigned via PostGIS spatial join |
| `geom` | POINT (SRID 4326) | PostGIS geometry with GIST index |

### `transit_stop_frequencies`

Per-stop departure counts by mode and time bucket.

| Column | Type | Description |
|---|---|---|
| `id` | bigint (PK) | Auto-increment |
| `gtfs_stop_id` | varchar(30) | References transit_stops |
| `mode_category` | varchar(20) | `bus`, `rail`, `subway`, `tram`, `ferry`, `on_demand` |
| `departures_06_09` | integer | Morning rush (06:00–08:59) |
| `departures_09_15` | integer | Midday (09:00–14:59) |
| `departures_15_18` | integer | Afternoon rush (15:00–17:59) |
| `departures_18_22` | integer | Evening (18:00–21:59) |
| `departures_06_20_total` | integer | Sum of all buckets |
| `distinct_routes` | integer | Unique route count |
| `day_type` | varchar(10) | Default `weekday` |
| `feed_version` | varchar(20), nullable | GTFS feed version (e.g., `2026-03`) |

Index on `(gtfs_stop_id, day_type)`.

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
| `safety_sensitivity` | decimal(4,2) | Safety modulation strength (0.0–1.5, default 1.0) |
| `catchment_km` | decimal | Scoring radius in km |
| `icon` | varchar | Icon identifier |
| `color` | varchar | Display color |
| `indicator_slug` | varchar | Associated indicator |
| `display_tier` | integer | Minimum tier to see on map |
| `category_group` | varchar | Grouping for UI |
| `is_active` | boolean | Whether active |
| `show_on_map` | boolean | Whether shown on map layer |

## Reports & Purchases

### `reports`

Paid neighborhood report purchases with full data snapshots frozen at generation time.

**Core columns:**

| Column | Type | Description |
|---|---|---|
| `uuid` | uuid, unique | Public identifier for URLs |
| `user_id` | bigint (FK, nullable) | References users (null for guest) |
| `guest_email` | varchar, nullable | Email for guest purchases |
| `lat` / `lng` | decimal(10,7) | Report coordinates |
| `address` | varchar, nullable | Reverse-geocoded address |
| `kommun_name` | varchar, nullable | Municipality name |
| `lan_name` | varchar, nullable | County name |
| `deso_code` | varchar, nullable | DeSO area code |
| `score` | decimal(5,2), nullable | Composite score at time of purchase |
| `score_label` | varchar, nullable | Human-readable score label |
| `stripe_session_id` | varchar, nullable | Stripe Checkout session ID |
| `stripe_payment_intent_id` | varchar, nullable | Stripe PaymentIntent ID |
| `amount_ore` | integer | Price in Swedish öre (7900 = 79 SEK, 0 for admin-generated) |
| `currency` | varchar | Currency code (`sek`) |
| `status` | varchar | `pending`, `completed`, `expired` |
| `view_count` | integer | Number of times report was viewed |

**Snapshot columns** (populated by `ReportGenerationService`):

| Column | Type | Description |
|---|---|---|
| `default_score` | decimal(6,2) | Area + proximity blended score |
| `personalized_score` | decimal(6,2) | Score adjusted for user priorities |
| `trend_1y` | decimal(6,2) | 1-year score change |
| `area_indicators` | json | All indicator snapshots with full history |
| `proximity_factors` | json | Amenity proximity scores |
| `schools` | json | Up to 10 nearby schools with stats |
| `category_verdicts` | json | 4 category verdicts (safety, economy, education, environment) |
| `score_history` | json | Historical composite scores by year |
| `deso_meta` | json | DeSO metadata (name, area, population, urbanity) |
| `national_references` | json | National median values per indicator |
| `map_snapshot` | json | GeoJSON geometries, surrounding DeSOs, school markers |
| `outlook` | json | Trend analysis + Swedish outlook narrative |
| `top_positive` | json | Top 5 strengths (>=75th percentile) |
| `top_negative` | json | Top 5 weaknesses (<=35th percentile) |
| `priorities` | json | User-selected priority categories |
| `isochrone` | json, nullable | Travel-time contour GeoJSON FeatureCollection |
| `isochrone_mode` | varchar(20), nullable | `pedestrian` or `auto` |
| `model_version` | varchar(20) | Algorithm version (e.g., `v1.0`) |
| `indicator_count` | integer | Total indicators in snapshot |
| `year` | integer, nullable | Latest data year |

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
