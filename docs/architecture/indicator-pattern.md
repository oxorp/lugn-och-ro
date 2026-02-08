# Indicator Pattern

> The core abstraction that makes the scoring system extensible — every data point becomes an indicator.

## Overview

The **indicator** is the fundamental unit of the PlatsIndex scoring system. Every piece of data — whether income, crime rate, school quality, or POI density — is modeled as an indicator with a consistent interface. This abstraction means adding a new data source requires zero changes to the scoring engine.

## The Pattern

An indicator has these properties:

| Property | Purpose | Example |
|---|---|---|
| `slug` | Machine identifier | `median_income` |
| `name` | Human-readable label | "Median Disposable Income" |
| `source` | Where data comes from | `scb`, `skolverket`, `bra` |
| `direction` | Whether higher is better | `positive`, `negative` |
| `weight` | Contribution to composite score | `0.20` (= 20%) |
| `normalization` | How to normalize | `rank_percentile` |
| `normalization_scope` | Rank against which peers | `national`, `urbanity_stratified` |
| `unit` | Measurement unit | `SEK`, `%`, `per_100k` |
| `category` | Grouping for display | `income`, `crime`, `education` |

## How It Works

### 1. Raw Values

Each indicator stores one `raw_value` per DeSO per year in the `indicator_values` table:

```
indicator_values:
  indicator_id  →  indicators.id
  deso_code     →  "0114A0010"
  year          →  2024
  raw_value     →  312000.00  (median income in SEK)
```

### 2. Normalization

The `normalize:indicators` command converts raw values to a 0–1 scale using percentile rank:

```sql
-- app/Services/NormalizationService.php
PERCENT_RANK() OVER (ORDER BY raw_value) as normalized_value
```

For `urbanity_stratified` indicators, the percentile is computed within each urbanity tier (urban, semi-urban, rural) rather than nationally.

### 3. Direction Handling

The `ScoringService` inverts negative indicators so that higher normalized values always mean "better":

```php
// app/Services/ScoringService.php
$directedValue = match($indicator->direction) {
    'positive' => $normalizedValue,      // higher income = better
    'negative' => 1.0 - $normalizedValue, // higher crime = worse
};
```

### 4. Weighted Sum

Each indicator contributes `directedValue × weight` to the composite score:

```
score = Σ(directedValue_i × weight_i) × 100
```

All weights must sum to 1.0 (enforced by admin dashboard validation).

## Adding a New Indicator

To add a new indicator to the system:

1. **Create the indicator row** in the `indicators` table (via seeder or admin UI)
2. **Write an ingestion command** that populates `indicator_values` with `raw_value` per DeSO
3. **Run normalization**: `normalize:indicators --year=2024`
4. **Run scoring**: `compute:scores --year=2024`

No changes needed to the normalization or scoring services — they read indicator metadata dynamically.

## Indicator Categories

There are two classes of indicators:

### Area-Level Indicators

Stored in `indicator_values` per DeSO, normalized via percentile rank, and used in the batch-computed area score (70% of blended score).

Categories: `income`, `employment`, `education`, `demographics`, `housing`, `crime`, `safety`, `financial_distress`, `amenities`, `transport`.

### Proximity Indicators

**Not** stored in `indicator_values`. Computed in real-time per coordinate by `ProximityScoreService`. Category = `proximity`, normalization = `none`.

These are registered in the `indicators` table primarily so their weights can be managed through the admin UI. The `ProximityResult` DTO reads weights from the DB (cached 5 min).

| Slug | Name | Direction | Weight |
|---|---|---|---|
| `prox_school` | School Proximity & Quality | positive | 0.10 |
| `prox_green_space` | Green Space Access | positive | 0.04 |
| `prox_transit` | Transit Access | positive | 0.05 |
| `prox_grocery` | Grocery Access | positive | 0.03 |
| `prox_negative_poi` | Negative POI Proximity | negative | 0.04 |
| `prox_positive_poi` | Positive POI Density | positive | 0.04 |

## Normalization Scope Rules

| Scope | Used For | Rationale |
|---|---|---|
| `national` | Income, employment, education, crime, debt | Socioeconomic rates are comparable across all areas |
| `urbanity_stratified` | POI density, transit access, healthcare | Access indicators penalize rural areas unfairly at national scale |
| `none` | Proximity indicators | Already 0–100 scores from distance decay; no normalization needed |

Rule of thumb: if it measures **physical access** → stratified. If it measures a **rate or outcome** → national. If it's computed in real-time per coordinate → none.

## Known Issues & Edge Cases

- Weight budget: all active indicator weights should sum to 1.0. The admin dashboard shows the current total and warns on deviation.
- Zero-weight indicators: some indicators (e.g., `foreign_background_pct`) exist in the database with weight `0.00` — intentionally excluded from scoring per legal review.
- Missing values: if a DeSO has no raw_value for an indicator, it gets `NULL` normalized_value and is excluded from that indicator's contribution (weight is redistributed).

## Related

- [Scoring Engine](/architecture/scoring-engine)
- [Normalization](/data-pipeline/normalization)
- [Master Indicator Reference](/indicators/)
