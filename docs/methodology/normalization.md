# Normalization Methodology

> Why percentile rank, how it works, and when to use urbanity stratification.

## Overview

Raw indicator values have vastly different scales (income in 100k SEK, crime in per-100k rates, percentages 0–100). Normalization converts all values to a comparable 0–1 scale.

## Why Percentile Rank

| Method | Pros | Cons | When To Use |
|---|---|---|---|
| **Percentile rank** | Robust to outliers, uniform output distribution | Loses magnitude information | Default — works for almost everything |
| Min-max | Preserves relative distances | Sensitive to extreme outliers | When uniform distribution expected |
| Z-score | Statistical interpretation | Can produce negative values | When normal distribution expected |

Percentile rank is the default because:
1. Government statistics often have extreme outliers (e.g., a few DeSOs with very high income)
2. We care about **relative position** (how does this area compare?), not absolute magnitudes
3. The output is always 0–1, making weighted combination straightforward

## National vs. Stratified

### National Scope

All 6,160 DeSOs ranked together. Used for **socioeconomic rates** where comparison across all areas is meaningful.

**Indicators**: income, employment, education, crime, debt, safety

### Urbanity-Stratified Scope

DeSOs ranked within their urbanity tier only. Used for **access/amenity indicators** where urban-rural comparison is misleading.

**Indicators**: grocery density, healthcare density, transit stops, restaurant density, fitness density, gambling density, pawn shop density, fast food density

**Why**: A rural DeSO with 2 grocery stores is well-served. An urban DeSO with 2 grocery stores is a food desert. National ranking would rate both the same.

## Direction Inversion

After normalization, the scoring engine inverts negative indicators:

```
directed_value = (direction == 'negative') ? 1.0 - normalized : normalized
```

This means:
- A DeSO at the 90th percentile for crime (very high crime) gets `1.0 - 0.9 = 0.1` (bad)
- A DeSO at the 10th percentile for crime (very low crime) gets `1.0 - 0.1 = 0.9` (good)

## Related

- [Normalization Pipeline](/data-pipeline/normalization)
- [Urbanity Classification](/methodology/urbanity)
- [Scoring Model](/methodology/scoring-model)
