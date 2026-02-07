# Normalization

> How raw indicator values are converted to a comparable 0–1 scale.

## Overview

The `NormalizationService` (`app/Services/NormalizationService.php`) transforms raw indicator values into normalized 0–1 values that can be compared across different indicators. The primary method is **percentile rank**, computed directly in PostgreSQL.

## How It Works

### Percentile Rank (Primary Method)

For each indicator, the normalized value represents where a DeSO falls relative to all others:

```sql
-- In NormalizationService
PERCENT_RANK() OVER (ORDER BY raw_value) as normalized_value
```

- `0.0` = lowest raw value across all DeSOs
- `0.5` = median
- `1.0` = highest raw value
- `NULL` raw values are excluded (normalized_value stays NULL)

### Normalization Scope

Two scoping strategies determine the comparison group:

#### National Scope

All 6,160 DeSOs ranked together. Used for socioeconomic indicators where comparison across all areas is meaningful.

**Used for**: income, employment, education, crime rates, debt rates, perceived safety

#### Urbanity-Stratified Scope

DeSOs ranked within their urbanity tier only:
- Urban DeSOs ranked against other urban DeSOs
- Semi-urban against semi-urban
- Rural against rural

**Used for**: POI density, transit access, healthcare proximity

**Rationale**: A rural DeSO with 2 grocery stores within 5km is well-served for its context, but would score poorly compared to urban areas with 50 stores. Stratified normalization prevents this unfair penalization.

### Other Methods

The system supports three normalization methods (configurable per indicator):

| Method | Formula | Use Case |
|---|---|---|
| `rank_percentile` | `PERCENT_RANK() OVER (ORDER BY raw_value)` | Default — robust to outliers |
| `min_max` | `(value - min) / (max - min)` | When uniform distribution is expected |
| `z_score` | `(value - mean) / stddev` | When normal distribution is expected |

In practice, all current indicators use `rank_percentile`.

## Running Normalization

```bash
php artisan normalize:indicators --year=2024
```

Options:
- `--year=2024` — Which year's data to normalize
- `--indicator=slug` — Normalize a specific indicator only (default: all active)

## The Normalization Process

1. Load all active indicators from the `indicators` table
2. For each indicator:
   - If scope is `national`: compute `PERCENT_RANK()` across all DeSOs with non-NULL raw_values
   - If scope is `urbanity_stratified`: compute `PERCENT_RANK()` within each urbanity tier
3. Update `indicator_values.normalized_value` via bulk UPDATE

## Urbanity Classification

Before normalization can use stratified scope, DeSOs must be classified. The `classify:deso-urbanity` command assigns tiers based on population density:

| Tier | Criteria |
|---|---|
| `urban` | High population density |
| `semi_urban` | Medium population density |
| `rural` | Low population density |

The classification is stored in `deso_areas.urbanity_tier`.

## Known Issues & Edge Cases

- **Tied values**: DeSOs with identical raw values get the same percentile rank. This is correct behavior.
- **Sparse indicators**: If an indicator only has data for 3,000 of 6,160 DeSOs, the percentile is computed among those 3,000 only. The remaining 3,160 get NULL normalized_value.
- **Employment data**: Only covers 5,835 DeSOs (old DeSO codes, max year 2021). This is the most coverage-limited indicator.
- **Zero values**: Zero is a valid value (e.g., 0% foreign background) and is included in normalization. NULL means "no data available" and is excluded.
- **Re-normalization**: If new data is ingested, ALL indicators should be re-normalized together to maintain consistent percentile rankings.

## Related

- [Indicator Pattern](/architecture/indicator-pattern)
- [Scoring](/data-pipeline/scoring)
- [Urbanity Classification](/methodology/urbanity)
- [Methodology: Normalization](/methodology/normalization)
