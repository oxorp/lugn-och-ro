# DeSO Explained

> What DeSO areas are, why they matter, and how they're used in PlatsIndex.

## What Are DeSOs?

**DeSO** (Demografiska statistikområden) are Sweden's finest-grained statistical areas, created by SCB (Statistics Sweden) in 2018. They subdivide all of Sweden into areas designed for demographic analysis.

| Property | Value |
|---|---|
| Full name | Demografiska statistikområden |
| Created by | SCB (Statistics Sweden) |
| First available | 2018 (applied retrospectively to 2004) |
| 2025 count | 6,160 areas |
| Previous count | 5,984 (2018 version) |
| Population range | ~700–2,700 per area |
| Boundary logic | Streets, rivers, railways, urbanicity, electoral districts |

## Why DeSOs?

| Alternative | Granularity | Why Not |
|---|---|---|
| Kommun (municipality) | 290 | Too coarse — Stockholm kommun contains both Östermalm and Rinkeby |
| RegSO | ~3,000 | Better but still lumps different neighborhoods together |
| H3 hexagons only | Variable | No government data is published at H3 level |
| Property-level | Individual | GDPR concerns; no aggregate statistics available |

DeSOs are the sweet spot: fine enough to distinguish neighborhoods, coarse enough that SCB publishes reliable statistics.

## DeSO Hierarchy

```
Sweden
  └─ Län (21 counties)
       └─ Kommun (290 municipalities)
            └─ RegSO (~3,000 regional statistical areas)
                 └─ DeSO (6,160 demographic statistical areas)
```

## 2025 Boundary Revision

SCB revised DeSO boundaries in 2025, increasing from 5,984 to 6,160 areas. Changes include splits (one DeSO becoming two), merges, and boundary adjustments.

The platform handles this via:
- `deso_boundary_changes` table tracks what changed
- `deso_code_mappings` maps old → new codes
- `trend_eligible` flag marks which areas can compute historical trends

## Related

- [Spatial Framework](/architecture/spatial-framework)
- [Disaggregation Methodology](/methodology/disaggregation)
