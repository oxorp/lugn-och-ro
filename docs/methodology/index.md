# Methodology Overview

> How and why the scoring model works the way it does.

## Overview

The PlatsIndex scoring methodology transforms diverse public data sources into a single 0–100 composite score per neighborhood. This section explains the key methodological choices.

## Key Concepts

| Concept | Summary | Page |
|---|---|---|
| Scoring Model | Weighted sum of normalized indicators with direction handling | [Scoring Model](/methodology/scoring-model) |
| Normalization | Percentile rank normalization with urbanity stratification | [Normalization](/methodology/normalization) |
| Meritvärde | The Swedish school grading system explained | [Merit Value](/methodology/meritvalue) |
| DeSO | Sweden's statistical micro-areas | [DeSO Explained](/methodology/deso-explained) |
| Disaggregation | How kommun-level data becomes DeSO estimates | [Disaggregation](/methodology/disaggregation) |
| Urbanity | Urban/rural classification affecting normalization | [Urbanity](/methodology/urbanity) |
| Legal | GDPR and Supreme Court constraints | [Legal Constraints](/methodology/legal-constraints) |

## Design Principles

1. **Transparency**: Every score can be decomposed into factor contributions
2. **Data-driven**: All weights are tunable via admin dashboard, not hardcoded
3. **Aggregate only**: No individual-level data — only government-published statistics
4. **Conservative disaggregation**: When estimating sub-area values, always constrain to known totals
5. **Urbanity-aware**: Access metrics use stratified normalization to avoid penalizing rural areas

## Related

- [Architecture Overview](/architecture/)
- [Indicator Pattern](/architecture/indicator-pattern)
- [Scoring Engine](/architecture/scoring-engine)
