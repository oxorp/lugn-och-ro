# Employment Indicators

> Employment rate for working-age residents.

## Overview

A single SCB indicator measuring employment, accounting for 5.5% of the composite score.

## `employment_rate` — Employment Rate (20-64)

| Property | Value |
|---|---|
| Source | SCB |
| SCB Table | AM0207 |
| Unit | percent |
| Direction | positive (higher = better) |
| Weight | 0.0550 (5.5%) |
| Normalization | rank_percentile, national scope |
| Coverage | 5,835 DeSOs (old DeSO codes, max year 2021) |

### What It Measures

The percentage of residents aged 20–64 who are employed (gainfully occupied). Published by SCB from the RAMS register-based labor market statistics.

### How It's Ingested

```bash
php artisan ingest:scb --indicator=employment_rate --year=2021
```

### Known Issues

- **Most coverage-limited indicator**: Only 5,835 of 6,160 DeSOs have data because the AM0207 table uses old DeSO codes (pre-2025 revision)
- **Stale data**: Latest available year is 2021 — 3 years behind other indicators
- The old → new DeSO code mapping catches most areas but ~325 DeSOs have no employment data
- DeSOs without employment data get NULL and the weight is redistributed to other indicators during scoring

## Related

- [Master Indicator Reference](/indicators/)
- [SCB Demographics Source](/data-sources/scb-demographics)
- [Income](/indicators/income)
