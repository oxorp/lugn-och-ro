# Urbanity Classification

> How DeSOs are classified as urban, semi-urban, or rural.

## Overview

DeSOs are classified into urbanity tiers to enable **urbanity-stratified normalization** for access/amenity indicators.

## Tiers

| Tier | Description | Typical Characteristics |
|---|---|---|
| `urban` | Dense urban areas | High population density, good amenity access |
| `semi_urban` | Suburban and small town | Moderate density, some amenities |
| `rural` | Countryside | Low density, limited amenities |

## Classification Method

The `classify:deso-urbanity` command assigns tiers based on population density and surrounding context. The classification is stored in `deso_areas.urbanity_tier`.

## Impact on Scoring

Only `urbanity_stratified` indicators are affected:

| Indicator | Without Stratification | With Stratification |
|---|---|---|
| `grocery_density` | Rural penalized for fewer stores | Rural ranked against rural peers |
| `transit_stop_density` | Rural penalized for fewer stops | Rural ranked against rural peers |

National-scope indicators (income, crime, debt) are NOT affected by urbanity classification — a low income is a low income regardless of urban/rural context.

## Related

- [Normalization Methodology](/methodology/normalization)
- [POI Indicators](/indicators/poi)
- [Transit Indicators](/indicators/transit)
