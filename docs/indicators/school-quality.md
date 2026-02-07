# School Quality Indicators

> Academic performance of schools within each DeSO area.

## Overview

Three indicators derived from Skolverket school statistics, aggregated to DeSO level. Together they account for 15.0% of the composite score. School quality is one of the strongest predictors of real estate value in Sweden.

## `school_merit_value_avg` — Average Merit Value

| Property | Value |
|---|---|
| Source | Skolverket |
| Unit | points (0–340) |
| Direction | positive (higher = better) |
| Weight | 0.0700 (7.0%) |
| Normalization | rank_percentile, national scope |
| Coverage | ~2,500 DeSOs (only those with grundskola reporting merit values) |

### What It Measures

The average **meritvärde** across all grundskola schools within the DeSO. Meritvärde is a composite score of a student's best 16 or 17 final grades, used for gymnasieskola admissions. Theoretical maximum is 340 points (17 subjects × 20 points each).

### How It's Computed

1. `ingest:skolverket-stats` fetches per-school merit values from the v3 API
2. `aggregate:school-indicators` averages them per DeSO

### Known Issues

- **Limited coverage**: Only ~40% of DeSOs have schools that report merit values
- **Academic year lag**: Most data is from 2020/21 (Skolverket restricted publication after that)
- **Small school sensitivity**: DeSOs with a single small school may have volatile merit values

## `school_goal_achievement_avg` — Goal Achievement Rate

| Property | Value |
|---|---|
| Source | Skolverket |
| Unit | percent |
| Direction | positive (higher = better) |
| Weight | 0.0450 (4.5%) |
| Coverage | ~2,500 DeSOs |

### What It Measures

Average percentage of students achieving passing grades in all subjects across schools in the DeSO.

## `school_teacher_certification_avg` — Teacher Certification Rate

| Property | Value |
|---|---|
| Source | Skolverket |
| Unit | percent |
| Direction | positive (higher = better) |
| Weight | 0.0350 (3.5%) |
| Coverage | ~3,800 DeSOs |

### What It Measures

Average percentage of teachers with full certification (behöriga lärare) across schools in the DeSO. Has significantly better coverage than merit value data.

### Known Issues

- Teacher certification data has much better coverage than merit/achievement data
- Some independent schools (friskolor) have very high certification rates but average academic results

## Related

- [Master Indicator Reference](/indicators/)
- [Merit Value Methodology](/methodology/meritvalue)
- [Skolverket Data Source](/data-sources/skolverket-schools)
