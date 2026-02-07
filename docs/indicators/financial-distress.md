# Financial Distress Indicators

> Debt rates, evictions, and median debt — sourced from Kronofogden via the Kolada API.

## Overview

Three indicators measure financial vulnerability, accounting for 10.0% of the composite score. All are disaggregated from kommun-level data to DeSO estimates.

## `debt_rate_pct` — Kronofogden Debt Rate

| Property | Value |
|---|---|
| Source | Kronofogden (via Kolada API) |
| Kolada KPI | N00989 |
| Unit | percent |
| Direction | negative (higher = worse) |
| Weight | 0.0500 (5.0%) |
| Normalization | rank_percentile, national scope |

### What It Measures

Estimated percentage of the adult population with active debts registered at Kronofogden (Swedish Enforcement Authority). Disaggregated from kommun-level data.

### How It's Computed

1. `ingest:kronofogden` fetches kommun-level data from Kolada API
2. `disaggregate:kronofogden` estimates DeSO-level rates using demographic weighting
3. `aggregate:kronofogden-indicators` creates indicator values

### Disaggregation Quality

Cross-validation R² = 0.4030 — demographics explain ~40% of debt rate variance. Estimates are constrained so the population-weighted DeSO average matches the known kommun rate.

## `eviction_rate` — Eviction Rate

| Property | Value |
|---|---|
| Source | Kronofogden (via Kolada API) |
| Kolada KPI | U00958 |
| Unit | per 100,000 inhabitants |
| Direction | negative (higher = worse) |
| Weight | 0.0300 (3.0%) |

### What It Measures

Estimated evictions per 100,000 inhabitants. Evictions are a lagging indicator of severe financial distress.

## `median_debt_sek` — Median Debt Amount

| Property | Value |
|---|---|
| Source | Kronofogden (via Kolada API) |
| Kolada KPI | N00990 |
| Unit | SEK |
| Direction | negative (higher = worse) |
| Weight | 0.0200 (2.0%) |

### What It Measures

Median debt amount per debtor at Kronofogden. Measured in SEK.

### Known Issues

- **Cannot be disaggregated**: Median of sub-areas ≠ sub-area medians. All DeSOs within a kommun share the same value.
- Lowest-weighted financial indicator for this reason — it adds kommun-level context but not DeSO-level differentiation.

## Disaggregation Formula

The Kronofogden disaggregation uses a weighted propensity model:

| Factor | Weight |
|---|---|
| Income (inverse) | 35% |
| Employment (inverse) | 20% |
| Education (inverse) | 15% |
| Low economic standard | 15% |
| Vulnerability area | 15% + 10% bonus for "särskilt utsatt" |

**Constraints:**
- Individual DeSO estimates clamped to 10%–300% of kommun rate
- Population-weighted DeSO average must match kommun rate exactly
- Does NOT use `foreign_background_pct` (legal/ethical constraint)

## Related

- [Master Indicator Reference](/indicators/)
- [Kronofogden Data Source](/data-sources/kronofogden-debt)
- [Disaggregation Methodology](/methodology/disaggregation)
- [Crime Indicators](/indicators/crime)
