# Indicator Master Reference

> Complete reference of all scoring indicators — their slugs, weights, sources, directions, and categories.

## Overview

PlatsIndex uses **two layers** of indicators to compute blended neighborhood scores:

1. **Area-level indicators** (27 indicators) — Pre-computed per DeSO, stored in `indicator_values`, normalized via percentile rank. Contribute **70%** of the blended score.
2. **Proximity indicators** (6 factors) — Computed in real-time per coordinate by `ProximityScoreService`. Contribute **30%** of the blended score.

After the proximity rebalancing (`ProximityIndicatorSeeder`), area-level weights are scaled by 0.753 so their total is ~70%.

## Area-Level Indicators

### Income (total weight: ~7.9%)

| Slug | Name | Source | Direction | Weight | Unit | Scope |
|---|---|---|---|---|---|---|
| `median_income` | Median Disposable Income | SCB | positive | 0.0489 | SEK | national |
| `low_economic_standard_pct` | Low Economic Standard (%) | SCB | negative | 0.0301 | percent | national |

### Employment (total weight: ~4.1%)

| Slug | Name | Source | Direction | Weight | Unit | Scope |
|---|---|---|---|---|---|---|
| `employment_rate` | Employment Rate (20-64) | SCB | positive | 0.0414 | percent | national |

### Education & Schools (total weight: ~15.8%)

| Slug | Name | Source | Direction | Weight | Unit | Scope |
|---|---|---|---|---|---|---|
| `education_post_secondary_pct` | Post-Secondary Education (%) | SCB | positive | 0.0286 | percent | national |
| `education_below_secondary_pct` | Below Secondary Education (%) | SCB | negative | 0.0166 | percent | national |
| `school_merit_value_avg` | Average Merit Value | Skolverket | positive | 0.0527 | points | national |
| `school_goal_achievement_avg` | Goal Achievement Rate | Skolverket | positive | 0.0339 | percent | national |
| `school_teacher_certification_avg` | Teacher Certification Rate | Skolverket | positive | 0.0264 | percent | national |

### Crime (total weight: ~13.2%)

| Slug | Name | Source | Direction | Weight | Unit | Scope |
|---|---|---|---|---|---|---|
| `crime_violent_rate` | Violent Crime Rate | BRÅ | negative | 0.0452 | per_100k | national |
| `crime_property_rate` | Property Crime Rate | BRÅ | negative | 0.0339 | per_100k | national |
| `crime_total_rate` | Total Crime Rate | BRÅ | negative | 0.0188 | per_100k | national |
| `vulnerability_flag` | Police Vulnerability Area | Polisen | negative | 0.0715 | flag | national |

### Safety (total weight: ~3.4%)

| Slug | Name | Source | Direction | Weight | Unit | Scope |
|---|---|---|---|---|---|---|
| `perceived_safety` | Perceived Safety (NTU) | BRÅ NTU | positive | 0.0339 | percent | national |

### Financial Distress (total weight: ~7.5%)

| Slug | Name | Source | Direction | Weight | Unit | Scope |
|---|---|---|---|---|---|---|
| `debt_rate_pct` | Kronofogden Debt Rate | Kronofogden | negative | 0.0377 | percent | national |
| `eviction_rate` | Eviction Rate | Kronofogden | negative | 0.0226 | per_100k | national |
| `median_debt_sek` | Median Debt Amount | Kronofogden | negative | 0.0151 | SEK | national |

### Amenities & Transport (total weight: ~14.3%)

| Slug | Name | Source | Direction | Weight | Unit | Scope |
|---|---|---|---|---|---|---|
| `grocery_density` | Grocery Access | OSM | positive | 0.0301 | per_1000 | urbanity_stratified |
| `healthcare_density` | Healthcare Access | OSM | positive | 0.0226 | per_1000 | urbanity_stratified |
| `restaurant_density` | Restaurant & Café Density | OSM | positive | 0.0151 | per_1000 | urbanity_stratified |
| `fitness_density` | Fitness & Sports Access | OSM | positive | 0.0151 | per_1000 | urbanity_stratified |
| `transit_stop_density` | Public Transport Stops | OSM | positive | 0.0301 | per_1000 | urbanity_stratified |
| `gambling_density` | Gambling Venue Density | OSM | negative | 0.0151 | per_1000 | urbanity_stratified |
| `pawn_shop_density` | Pawn Shop Density | OSM | negative | 0.0075 | per_1000 | urbanity_stratified |
| `fast_food_density` | Late-Night Fast Food Density | OSM | negative | 0.0075 | per_1000 | urbanity_stratified |

### Informational (weight: 0%, not scored)

| Slug | Name | Source | Direction | Weight | Notes |
|---|---|---|---|---|---|
| `foreign_background_pct` | Foreign Background (%) | SCB | neutral | 0.0000 | Excluded from scoring — legal/ethical constraint |
| `population` | Population | SCB | neutral | 0.0000 | Used as denominator, not as scoring input |
| `rental_tenure_pct` | Rental Housing (%) | SCB | neutral | 0.0000 | Informational — used in disaggregation formulas |

## Proximity Indicators (30%)

Computed in real-time per coordinate. See [Proximity Indicators](/indicators/proximity) for full details.

| Slug | Name | Direction | Weight | Radius | Scoring Logic |
|---|---|---|---|---|---|
| `prox_school` | School Proximity & Quality | positive | 0.10 | 2 km | Best school's merit × distance decay |
| `prox_green_space` | Green Space Access | positive | 0.04 | 1 km | Distance to nearest park/nature reserve |
| `prox_transit` | Transit Access | positive | 0.05 | 1 km | Nearest stop + mode bonus + count bonus |
| `prox_grocery` | Grocery Access | positive | 0.03 | 1 km | Distance to nearest grocery store |
| `prox_negative_poi` | Negative POI Proximity | negative | 0.04 | 500 m | Penalty per nearby negative POI |
| `prox_positive_poi` | Positive POI Density | positive | 0.04 | 1 km | Bonus from amenities, diminishing returns |

## Weight Budget Summary

| Category | Total Weight | Indicator Count | Type |
|---|---|---|---|
| Education & Schools | 15.8% | 5 | Area |
| Amenities & Transport | 14.3% | 8 | Area |
| Crime | 13.2% | 4 | Area |
| **Proximity** | **30.0%** | **6** | **Real-time** |
| Income | 7.9% | 2 | Area |
| Financial Distress | 7.5% | 3 | Area |
| Employment | 4.1% | 1 | Area |
| Safety | 3.4% | 1 | Area |
| **Total** | **~100%** | **30 scored + 3 informational** | |

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
| `none` | Already 0–100 | Proximity indicators (real-time distance decay) |

## Related

- [Indicator Pattern](/architecture/indicator-pattern)
- [Scoring Engine](/architecture/scoring-engine)
- [Proximity](/indicators/proximity)
- [Income](/indicators/income)
- [Employment](/indicators/employment)
- [Education](/indicators/education)
- [School Quality](/indicators/school-quality)
- [Crime](/indicators/crime)
- [Financial Distress](/indicators/financial-distress)
- [POI](/indicators/poi)
