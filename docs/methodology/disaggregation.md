# Disaggregation

> How kommun-level and län-level data is estimated at DeSO level.

## Overview

Several data sources provide values at kommun (290 areas) or län (21 areas) level, but PlatsIndex needs DeSO-level values (6,160 areas). **Disaggregation** estimates sub-area values using demographic proxies while constraining to known totals.

## Method: Demographic-Weighted Propensity

### Step 1: Compute Propensity Weights

For each DeSO within a kommun, compute a **propensity weight** based on available demographic factors:

| Factor | Weight in Crime Formula | Weight in Debt Formula |
|---|---|---|
| Income (inverse) | 35% | 35% |
| Employment (inverse) | 20% | 20% |
| Education (inverse) | 15% | 15% |
| Low economic standard | — | 15% |
| Vulnerability area overlap | 30% + 20% bonus | 15% + 10% bonus |

Higher propensity = the DeSO is more likely to have higher crime/debt rates.

### Step 2: Distribute Kommun Values

```
deso_estimate = kommun_rate × (deso_propensity / avg_propensity_in_kommun)
```

DeSOs with higher propensity get rates above the kommun average; lower propensity DeSOs get rates below.

### Step 3: Clamp Extremes

Individual DeSO estimates are clamped to 10%–300% of the kommun rate to prevent unrealistic extremes.

### Step 4: Constrain to Kommun Total

The population-weighted average of all DeSO estimates within a kommun must match the known kommun rate exactly:

```
constraint: Σ(deso_estimate_i × deso_population_i) / Σ(deso_population_i) = kommun_rate
```

This is achieved by proportional scaling after clamping.

## Validation

### Cross-Validation Results

For Kronofogden debt rates:
- **R² = 0.4030** — demographics explain ~40% of variance
- This is typical for small area estimation where ground truth is unavailable

### Sanity Checks

- High-debt DeSOs should overlap with police vulnerability areas
- Known affluent areas (Danderyd, Lidingö) should have low estimated rates
- Sentinel area checks verify expected patterns

## What's NOT Used

- **`foreign_background_pct`** is explicitly excluded from all disaggregation formulas per legal/ethical review
- Using ethnic composition as a predictor variable would raise GDPR and discrimination concerns

## Historical Disaggregation via Crosswalk

For historical data (2019–2023), an additional step is required because SCB used **DeSO 2018 codes** (5,984 areas) which don't match current DeSO 2025 codes (6,160 areas).

The `CrosswalkService` maps old values to new codes using the `deso_crosswalk` table:

- **Rate/percentage values**: Assigned directly on 1:1 mappings; area-weighted average on merges
- **Count values**: Distributed proportionally by `overlap_fraction` on splits

This happens transparently inside `ingest:scb-historical` and `ingest:bra-historical`.

See [Spatial Framework — DeSO Crosswalk](/architecture/spatial-framework#deso-2018-↔-2025-crosswalk) for technical details.

## Limitations

- Disaggregation assumes the relationship between demographics and the target variable is uniform within a kommun
- The 40% R² means 60% of variance is unexplained — DeSO estimates are approximations
- Areas with unusual characteristics (e.g., a university campus in a wealthy kommun) may be mislabeled
- Median values cannot be disaggregated (median of sub-areas ≠ sub-area medians)
- Historical crosswalk introduces additional approximation for split areas (~10% of DeSOs)

## Related

- [Crime Indicators](/indicators/crime)
- [Financial Distress Indicators](/indicators/financial-distress)
- [Aggregation Pipeline](/data-pipeline/aggregation)
