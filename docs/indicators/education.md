# Education Indicators

> Population education levels — post-secondary attainment and below-secondary risk.

## Overview

Two SCB indicators measure the educational composition of a DeSO's population. These are distinct from school quality indicators (which measure the schools located within a DeSO).

## `education_post_secondary_pct` — Post-Secondary Education

| Property | Value |
|---|---|
| Source | SCB |
| SCB Table | UF0506 |
| Unit | percent |
| Direction | positive (higher = better) |
| Weight | 0.0380 (3.8%) |
| Normalization | rank_percentile, national scope |

### What It Measures

Percentage of residents aged 25–64 with post-secondary education (university degree, yrkeshögskola, or equivalent).

## `education_below_secondary_pct` — Below Secondary Education

| Property | Value |
|---|---|
| Source | SCB |
| SCB Table | UF0506 |
| Unit | percent |
| Direction | negative (higher = worse) |
| Weight | 0.0220 (2.2%) |
| Normalization | rank_percentile, national scope |

### What It Measures

Percentage of residents aged 25–64 without completed secondary education (gymnasium). A risk marker for long-term economic disadvantage.

### Known Issues

- These two indicators are inversely correlated but not perfectly complementary (there's a middle group with secondary-only education)
- Both are used in the Kronofogden and crime disaggregation formulas as predictor variables

## Related

- [Master Indicator Reference](/indicators/)
- [School Quality](/indicators/school-quality)
- [SCB Demographics Source](/data-sources/scb-demographics)
