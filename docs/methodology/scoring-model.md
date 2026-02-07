# Scoring Model

> How the 0–100 composite score is computed from individual indicators.

## Overview

The composite score uses a **weighted linear combination** of normalized, direction-adjusted indicator values. This is intentionally simple — interpretability matters more than model complexity for this use case.

## Formula

```
score = Σ(directed_value_i × adjusted_weight_i) × 100
```

Where:
- `directed_value_i` = normalized value with direction applied (0–1)
- `adjusted_weight_i` = indicator weight, redistributed if some indicators are missing

## Direction Handling

| Direction | Formula | Example |
|---|---|---|
| positive | `directed = normalized` | Higher income → higher score |
| negative | `directed = 1.0 - normalized` | Higher crime → lower score |
| neutral | excluded | Not included in scoring |

## Weight Redistribution

If a DeSO lacks data for some indicators:

```
available_weight_sum = sum of weights for indicators with data
adjusted_weight_i = weight_i / available_weight_sum
```

This ensures scores are comparable even with incomplete data.

## Factor Attribution

For each DeSO, the scoring engine computes per-indicator contributions:

```
contribution_i = directed_value_i × adjusted_weight_i
```

The top 3 positive and top 3 negative contributors are stored as `top_positive` and `top_negative` in the composite scores table.

## Score Bands

| Score | Label | Map Color |
|---|---|---|
| 80–100 | Strong Growth Area | Dark Green |
| 60–79 | Stable / Positive Outlook | Light Green |
| 40–59 | Mixed Signals | Yellow |
| 20–39 | Elevated Risk | Orange |
| 0–19 | High Risk / Declining | Red |

## Related

- [Scoring Engine](/architecture/scoring-engine)
- [Normalization](/methodology/normalization)
- [Master Indicator Reference](/indicators/)
