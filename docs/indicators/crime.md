# Crime Indicators

> Crime rates and police vulnerability classifications — the safety dimension.

## Overview

Five indicators measure the crime and safety profile of each DeSO area, accounting for 22.0% of the composite score (crime 17.5% + safety 4.5%). This is the heaviest-weighted category, reflecting the strong correlation between safety and property values.

## `crime_violent_rate` — Violent Crime Rate

| Property | Value |
|---|---|
| Source | BRÅ |
| Unit | per 100,000 inhabitants |
| Direction | negative (higher = worse) |
| Weight | 0.0600 (6.0%) |
| Normalization | rank_percentile, national scope |

### What It Measures

Estimated violent crimes per 100,000 inhabitants. Includes person crimes (assault, threats), robbery, and sexual crimes. Disaggregated from kommun-level BRÅ data.

## `crime_property_rate` — Property Crime Rate

| Property | Value |
|---|---|
| Source | BRÅ |
| Unit | per 100,000 inhabitants |
| Direction | negative (higher = worse) |
| Weight | 0.0450 (4.5%) |

### What It Measures

Estimated property crimes per 100,000 inhabitants. Includes theft, burglary, and criminal damage.

## `crime_total_rate` — Total Crime Rate

| Property | Value |
|---|---|
| Source | BRÅ |
| Unit | per 100,000 inhabitants |
| Direction | negative (higher = worse) |
| Weight | 0.0250 (2.5%) |

### What It Measures

Estimated total reported crimes per 100,000 inhabitants. Lower weight than sub-categories because it overlaps with violent and property rates.

## `vulnerability_flag` — Police Vulnerability Area

| Property | Value |
|---|---|
| Source | Polisen |
| Unit | flag (0/1/2) |
| Direction | negative (higher = worse) |
| Weight | 0.0950 (9.5%) |

### What It Measures

Police classification of vulnerability areas:
- `0` = Not flagged (most DeSOs)
- `1` = Utsatt område (vulnerable area, 46 areas)
- `2` = Särskilt utsatt område (particularly vulnerable, 19 areas)

This is the **highest-weighted single indicator** because vulnerability classification is the strongest available signal for neighborhood trajectory. ~275 DeSOs have ≥25% overlap with a vulnerability area.

### How It's Computed

1. `ingest:vulnerability-areas` imports police GeoJSON polygons
2. PostGIS computes overlap between DeSO polygons and vulnerability polygons
3. `deso_vulnerability_mapping` stores overlap fractions
4. DeSOs with ≥25% overlap get flagged with the tier of the overlapping vulnerability area

## `perceived_safety` — Perceived Safety (NTU)

| Property | Value |
|---|---|
| Source | BRÅ NTU survey |
| Unit | percent |
| Direction | positive (higher = better) |
| Weight | 0.0450 (4.5%) |
| Category | safety |

### What It Measures

Estimated percentage of residents who feel safe outdoors at night. Derived from the National Crime Survey (NTU) which surveys ~200,000 respondents annually.

### Known Issues

- **Coarse source data**: NTU is at län level (21 regions) — disaggregated to 6,160 DeSOs using inverted demographic weighting (safer demographics → higher safety score)
- **Subjective measure**: Perceived safety can diverge from actual crime rates

## Disaggregation Method

Crime and safety indicators use demographic-weighted disaggregation from kommun/län to DeSO level:

| Factor | Weight in Formula |
|---|---|
| Income (inverse) | 35% |
| Employment (inverse) | 20% |
| Education (inverse) | 15% |
| Vulnerability overlap | 30% + 20% bonus for "särskilt utsatt" |

See [Disaggregation Methodology](/methodology/disaggregation) for details.

## Related

- [Master Indicator Reference](/indicators/)
- [BRÅ Crime Data Source](/data-sources/bra-crime)
- [Disaggregation Methodology](/methodology/disaggregation)
- [Financial Distress](/indicators/financial-distress)
