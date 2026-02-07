# Data Quality

> Known data gaps, validation checks, and quality monitoring.

## Overview

The platform includes several mechanisms for data quality assurance: automated validation rules, sentinel area checks, score drift detection, and freshness monitoring.

## Validation System

### Validation Rules (`validation_rules` table)

Pre-defined checks that run after ingestion or scoring:

| Rule Type | Example |
|---|---|
| Range check | "median_income raw_value must be between 50,000 and 2,000,000" |
| Coverage check | "indicator must have values for ≥95% of DeSOs" |
| Consistency check | "kommun average of DeSO estimates must match kommun actual ±5%" |
| Distribution check | "normalized values should be roughly uniform (0–1)" |

### Validation Service (`DataValidationService`)

Runs validation rules and stores results in `validation_results`:

- `passed`: Rule check succeeded
- `failed`: Rule check failed — flag for review
- `warning`: Marginal — may need attention

## Sentinel Areas

Pre-selected DeSOs with well-known characteristics used as sanity checks:

| Sentinel | Expected Behavior |
|---|---|
| Danderyd (wealthy suburb) | High income, high school quality, low crime |
| Rinkeby (vulnerable area) | Low income, high crime, vulnerability tier 2 |
| Rural Norrland area | Low density, limited amenities, average income |

The `check:sentinels` command verifies that sentinel area scores align with expectations.

## Score Drift Detection

The `ScoreDriftDetector` service (`app/Services/ScoreDriftDetector.php`) monitors changes between score versions:

- **Individual drift**: Flags DeSOs with score changes >15 points
- **Distribution drift**: Detects systematic shifts in mean/median scores
- **Category drift**: Monitors changes by urbanity tier

Results are displayed on the admin Data Quality dashboard.

## Freshness Monitoring

The `check:freshness` command monitors data age:

- Each indicator tracks `last_ingested_at`
- Alerts when data exceeds expected refresh intervals
- Displayed on the admin Pipeline dashboard

## Known Data Gaps

### Coverage Limitations

| Indicator | Coverage | Reason |
|---|---|---|
| `employment_rate` | 5,835 / 6,160 DeSOs | Uses old DeSO codes (AM0207 max year 2021) |
| `school_merit_value_avg` | ~2,500 / 6,160 | Only DeSOs with grundskola that report merit values |
| `teacher_certification_avg` | ~3,800 / 6,160 | Better coverage than merit values |
| Crime indicators | 6,160 (estimated) | All DeSOs have estimates, but precision varies |
| Debt indicators | 6,160 (estimated) | All DeSOs have estimates, R²=0.40 |
| POI density | Variable | Zero is valid data; NULL means unmeasured |

### Suppressed Data

- SCB suppresses income data for DeSOs with fewer than ~50 residents (statistical confidentiality)
- Skolverket suppresses school statistics when student count is very low
- BRÅ uses `..` in CSV for suppressed values and `-` for zero

### Edge Cases

- **DeSO boundary changes (2025)**: ~176 DeSOs were split/merged. Historical data for these areas is not directly comparable.
- **Skolverket coordinate quality**: ~15% of schools have NULL coordinates in the registry API
- **NTU survey granularity**: Perceived safety data is at län level (21 regions), disaggregated to 6,160 DeSOs — precision is limited
- **Kronofogden median debt**: Cannot be disaggregated (median of sub-areas ≠ sub-area medians). All DeSOs in a kommun share the same median debt value.

## Related

- [Data Pipeline Overview](/data-pipeline/)
- [Scoring](/data-pipeline/scoring)
- [Admin Dashboard](/frontend/admin-dashboard)
- [Troubleshooting](/operations/troubleshooting)
