# Income Indicators

> Median disposable income and low economic standard — core measures of area affluence.

## Overview

Two SCB-sourced indicators measure the financial wellbeing of DeSO residents. Together they account for 10.5% of the composite score.

## `median_income` — Median Disposable Income

| Property | Value |
|---|---|
| Source | SCB (Statistics Sweden) |
| SCB Table | HE0110 |
| Unit | SEK (Swedish kronor) |
| Direction | positive (higher = better) |
| Weight | 0.0650 (6.5%) |
| Normalization | rank_percentile, national scope |
| Coverage | ~6,160 DeSOs (2024 data with DeSO 2025 codes) |

### What It Measures

The median disposable income of all individuals aged 20+ within a DeSO area. Disposable income = total income minus taxes and mandatory contributions. Published annually by SCB.

### How It's Ingested

```bash
php artisan ingest:scb --indicator=median_income --year=2024
```

The `ScbApiService` POSTs to the PX-Web API with table path `HE/HE0110/HE0110A/TabVX1DeSO` and parses the JSON-stat2 response.

### Known Issues

- SCB suppresses values for DeSOs with very few residents (statistical confidentiality)
- Income data is typically 1–2 years behind the current year
- DeSO 2025 codes have `_DeSO2025` suffix in responses — stripped by `extractDesoCode()`

## `low_economic_standard_pct` — Low Economic Standard

| Property | Value |
|---|---|
| Source | SCB |
| SCB Table | HE0110 |
| Unit | percent |
| Direction | negative (higher = worse) |
| Weight | 0.0400 (4.0%) |
| Normalization | rank_percentile, national scope |

### What It Measures

Percentage of residents living below the EU's "at risk of poverty" threshold — typically 60% of national median equivalised disposable income. A direct measure of economic vulnerability.

### Known Issues

- Correlates strongly with `median_income` (~-0.8 correlation). Both are included because they capture different aspects: absolute level vs. poverty concentration.

## Related

- [Master Indicator Reference](/indicators/)
- [SCB Demographics Source](/data-sources/scb-demographics)
- [Employment](/indicators/employment)
