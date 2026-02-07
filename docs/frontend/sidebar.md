# Sidebar

> Score display, indicator breakdown, and area comparison.

## Map Page Sidebar

**File**: `resources/js/pages/map.tsx`

The sidebar is the right panel of the map page. It shows detailed information when a DeSO area is selected.

### Sections (top to bottom)

1. **Area header** — DeSO name, kommun, lan, urbanity tier badge
2. **Composite score** — Large score number with color-matched progress bar and label
3. **Score band label** — "Strong Growth", "Positive", "Mixed", "Challenging", "High Risk"
4. **Trend indicator** — Arrow up/down/stable with 1-year change
5. **Factor scores** — Per-indicator contribution bars
6. **Top positive / negative** — Badges highlighting strongest and weakest indicators
7. **Schools section** — School list (tier-gated, loaded on demand)
8. **Crime section** — Crime rates and safety info (tier-gated)
9. **Financial section** — Debt rates and distress indicators (tier-gated)
10. **Unlock prompt** — For free-tier users, shows pricing to unlock detailed data

### Score Bands

| Score Range | Label | Color |
|---|---|---|
| 80-100 | Strong Growth | Green |
| 60-79 | Positive | Light green |
| 40-59 | Mixed | Yellow |
| 20-39 | Challenging | Red-purple |
| 0-19 | High Risk | Purple |

### Tier-Gated Content

The sidebar adapts to the user's data tier. Lower tiers see blurred previews and lock icons with upgrade prompts.

## Comparison Sidebar

**File**: `resources/js/components/comparison-sidebar.tsx`

Activated when two comparison pins are placed. Shows side-by-side analysis.

### Layout

1. **Header** — Location labels (A = blue, B = amber) with kommun/lan info
2. **Distance** — Kilometers between the two points
3. **Composite scores** — Side-by-side score bars with point difference
4. **Indicator breakdown** — Per-indicator comparison bars showing:
   - Direction-adjusted percentile for A and B
   - Raw values with units
   - "A higher by X%", "B higher by X%", or "Similar"
5. **Verdict** — Badges grouped by: A stronger, B stronger, Similar
6. **Actions** — Share link (copies URL with compare params) and PDF export (locked)

### Comparison URL Format

```
?compare={lat_a},{lng_a}|{lat_b},{lng_b}
```

### Indicator Sorting

Indicators are sorted by weight (highest first) so the most important factors appear at the top.

## Internationalization

All text uses the `useTranslation()` hook with keys like:
- `sidebar.score.strong_growth`
- `sidebar.indicators.labels.median_income`
- `compare.a_stronger`

## Related

- [Map Rendering](/frontend/map-rendering)
- [DeSO Indicators API](/api/deso-indicators)
- [Tiering Model](/business/tiering-model)
