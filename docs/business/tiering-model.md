# Tiering Model

> Six-tier access system controlling data granularity and features.

## Tier Hierarchy

| Tier | Value | Access | Data Granularity |
|---|---|---|---|
| Public | 0 | No account | Indicator names and categories only (blurred preview) |
| Free Account | 1 | Registered, no payment | 5-level percentile band, quantized bar width, trend direction |
| Unlocked | 2 | One-time area purchase | 8-level percentile band, approximate raw values, trend band |
| Subscriber | 3 | Monthly/annual plan | Exact percentiles, exact values, full trend history |
| Enterprise | 4 | Custom contract | API access, custom weights, tenant isolation |
| Admin | 99 | Internal | Everything + weight, contribution, rank, coverage, API paths |

## Tier Resolution Logic

Evaluated top-down — first match wins:

```
Admin?          → Tier 99
API access?     → Tier 4 (Enterprise)
Subscription?   → Tier 3 (Subscriber)
Area unlocked?  → Tier 2 (Unlocked)
Registered?     → Tier 1 (Free Account)
Anonymous?      → Tier 0 (Public)
```

For area unlocks, hierarchy is checked: DeSO → kommun → lan. A kommun unlock covers all DeSOs in that kommun.

## Data Obfuscation by Tier

### Public (Tier 0)
- Slug, name, category
- `locked: true`
- No values

### Free Account (Tier 1)
- 5-level percentile band: very_high, high, average, low, very_low
- Bar width quantized to nearest 5% (prevents exact percentile inference)
- Trend direction only (rising/falling/stable)

### Unlocked (Tier 2)
- 8-level percentile band: top_5, top_10, top_25, upper_half, lower_half, bottom_25, bottom_10, bottom_5
- Approximate raw value (rounded: ~5,000 kr, ~42%, ~150)
- Trend direction + band (large/moderate/small/minimal)
- Short description, source name, data vintage

### Subscriber (Tier 3)
- Exact percentile and normalized value
- Exact raw value with unit
- Full trend object with history
- Methodology note, national context
- Source URL, last ingested date

### Admin (Tier 99)
All subscriber fields plus:
- Weight, weighted contribution
- Rank and rank total
- Normalization method
- Coverage count and total
- Source API path, field code
- Data quality notes, admin notes

## Pricing

| Product | Price | Currency |
|---|---|---|
| DeSO unlock | 79 SEK | One-time |
| Kommun unlock | 199 SEK | One-time |
| Monthly subscription | 349 SEK/month | Recurring |
| Annual subscription | 2,990 SEK/year | Recurring |

Prices stored in ore (7900 = 79 SEK).

## View-As Simulation

Admins can simulate any tier via session override:

```
POST /admin/view-as   { "tier": 2 }
DELETE /admin/view-as
```

The `DataTieringService.resolveEffectiveTier()` checks session override before actual tier.

## Implementation

**Service**: `DataTieringService`
**Enum**: `DataTier` (backed integer enum)
**Controller**: Each API endpoint calls `resolveEffectiveTier()` and passes the result to tier-specific response transformers.

## Related

- [DeSO Indicators API](/api/deso-indicators)
- [DeSO Schools API](/api/deso-schools)
- [Target Customers](/business/target-customers)
