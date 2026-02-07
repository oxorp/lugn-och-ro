# Indicator Master Reference

> Complete reference of all scoring indicators — their slugs, weights, sources, directions, and categories.

## Overview

PlatsIndex uses **27 indicators** across 7 categories to compute composite neighborhood scores. Each indicator measures a specific aspect of area quality, normalized to a 0–1 scale and combined via weighted sum.

The latest weights (after POI rebalancing via `PoiIndicatorSeeder`) are shown below.

## Master Indicator Table

### Income (total weight: 10.5%)

| Slug | Name | Source | Direction | Weight | Unit | Scope |
|---|---|---|---|---|---|---|
| `median_income` | Median Disposable Income | SCB | positive | 0.0650 | SEK | national |
| `low_economic_standard_pct` | Low Economic Standard (%) | SCB | negative | 0.0400 | percent | national |

### Employment (total weight: 5.5%)

| Slug | Name | Source | Direction | Weight | Unit | Scope |
|---|---|---|---|---|---|---|
| `employment_rate` | Employment Rate (20-64) | SCB | positive | 0.0550 | percent | national |

### Education & Schools (total weight: 21.0%)

| Slug | Name | Source | Direction | Weight | Unit | Scope |
|---|---|---|---|---|---|---|
| `education_post_secondary_pct` | Post-Secondary Education (%) | SCB | positive | 0.0380 | percent | national |
| `education_below_secondary_pct` | Below Secondary Education (%) | SCB | negative | 0.0220 | percent | national |
| `school_merit_value_avg` | Average Merit Value | Skolverket | positive | 0.0700 | points | national |
| `school_goal_achievement_avg` | Goal Achievement Rate | Skolverket | positive | 0.0450 | percent | national |
| `school_teacher_certification_avg` | Teacher Certification Rate | Skolverket | positive | 0.0350 | percent | national |

### Crime (total weight: 17.5%)

| Slug | Name | Source | Direction | Weight | Unit | Scope |
|---|---|---|---|---|---|---|
| `crime_violent_rate` | Violent Crime Rate | BRÅ | negative | 0.0600 | per_100k | national |
| `crime_property_rate` | Property Crime Rate | BRÅ | negative | 0.0450 | per_100k | national |
| `crime_total_rate` | Total Crime Rate | BRÅ | negative | 0.0250 | per_100k | national |
| `vulnerability_flag` | Police Vulnerability Area | Polisen | negative | 0.0950 | flag | national |

### Safety (total weight: 4.5%)

| Slug | Name | Source | Direction | Weight | Unit | Scope |
|---|---|---|---|---|---|---|
| `perceived_safety` | Perceived Safety (NTU) | BRÅ NTU | positive | 0.0450 | percent | national |

### Financial Distress (total weight: 10.0%)

| Slug | Name | Source | Direction | Weight | Unit | Scope |
|---|---|---|---|---|---|---|
| `debt_rate_pct` | Kronofogden Debt Rate | Kronofogden | negative | 0.0500 | percent | national |
| `eviction_rate` | Eviction Rate | Kronofogden | negative | 0.0300 | per_100k | national |
| `median_debt_sek` | Median Debt Amount | Kronofogden | negative | 0.0200 | SEK | national |

### Amenities & Transport (total weight: 19.0%)

| Slug | Name | Source | Direction | Weight | Unit | Scope |
|---|---|---|---|---|---|---|
| `grocery_density` | Grocery Access | OSM | positive | 0.0400 | per_1000 | urbanity_stratified |
| `healthcare_density` | Healthcare Access | OSM | positive | 0.0300 | per_1000 | urbanity_stratified |
| `restaurant_density` | Restaurant & Café Density | OSM | positive | 0.0200 | per_1000 | urbanity_stratified |
| `fitness_density` | Fitness & Sports Access | OSM | positive | 0.0200 | per_1000 | urbanity_stratified |
| `transit_stop_density` | Public Transport Stops | OSM | positive | 0.0400 | per_1000 | urbanity_stratified |
| `gambling_density` | Gambling Venue Density | OSM | negative | 0.0200 | per_1000 | urbanity_stratified |
| `pawn_shop_density` | Pawn Shop Density | OSM | negative | 0.0100 | per_1000 | urbanity_stratified |
| `fast_food_density` | Late-Night Fast Food Density | OSM | negative | 0.0100 | per_1000 | urbanity_stratified |

### Informational (weight: 0%, not scored)

| Slug | Name | Source | Direction | Weight | Notes |
|---|---|---|---|---|---|
| `foreign_background_pct` | Foreign Background (%) | SCB | neutral | 0.0000 | Excluded from scoring — legal/ethical constraint |
| `population` | Population | SCB | neutral | 0.0000 | Used as denominator, not as scoring input |
| `rental_tenure_pct` | Rental Housing (%) | SCB | neutral | 0.0000 | Informational — used in disaggregation formulas |

## Weight Budget Summary

| Category | Total Weight | Indicator Count |
|---|---|---|
| Education & Schools | 21.0% | 5 |
| Amenities & Transport | 19.0% | 8 |
| Crime | 17.5% | 4 |
| Income | 10.5% | 2 |
| Financial Distress | 10.0% | 3 |
| Employment | 5.5% | 1 |
| Safety | 4.5% | 1 |
| **Unallocated** | **12.0%** | — |
| **Total** | **100%** | **24 scored + 3 informational** |

::: warning Unallocated Weight
12% of the weight budget is currently unallocated, reserved for future indicators (transit frequency, property price trends, green space access). The scoring engine normalizes available weights, so the unallocated portion doesn't create a gap — it's redistributed across existing indicators at scoring time.
:::

## Direction Semantics

| Direction | Meaning | Normalization Effect |
|---|---|---|
| `positive` | Higher raw value = better area | Score contribution = normalized_value × weight |
| `negative` | Higher raw value = worse area | Score contribution = (1 - normalized_value) × weight |
| `neutral` | Not scored | Excluded from composite score entirely |

## Normalization Scope

| Scope | Comparison Group | Used For |
|---|---|---|
| `national` | All 6,160 DeSOs ranked together | Socioeconomic rates (income, crime, debt) |
| `urbanity_stratified` | Ranked within urbanity tier (urban/semi-urban/rural) | Access metrics (POI density, transit) |

## Related

- [Indicator Pattern](/architecture/indicator-pattern)
- [Scoring Engine](/architecture/scoring-engine)
- [Income](/indicators/income)
- [Employment](/indicators/employment)
- [Education](/indicators/education)
- [School Quality](/indicators/school-quality)
- [Crime](/indicators/crime)
- [Financial Distress](/indicators/financial-distress)
- [POI](/indicators/poi)
