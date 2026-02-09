# TASK: Indicator Trend Arrows + Tooltip Sparklines

## Context

We now have 6 years of historical data (2019-2024) for most indicators. The sidebar currently shows a static snapshot — "Median Income: 78th percentile (287,000 SEK)". There's no indication of whether this is improving, declining, or flat.

Add two things:
1. **Trend arrow** next to the percentile — small colored arrow with the year-over-year percentile change
2. **Sparkline in the tooltip** — tiny line chart showing the indicator's trajectory over all available years

---

## What It Looks Like

### Sidebar Indicator Row (Current)

```
Median Income          ████████░░  78:e percentilen
                                   (287 000 kr)
```

### Sidebar Indicator Row (After)

```
Median Income          ████████░░  78:e percentilen  ↑ +3
                                   (287 000 kr)
```

The `↑ +3` means: this DeSO moved from the 75th to the 78th percentile in the last year. Green arrow, green text.

### Arrow Rules

| Change | Arrow | Color | Example |
|---|---|---|---|
| +3 or more percentile points | ↑ | Green (#27ae60) | ↑ +5 |
| +1 to +2 | ↗ | Light green (#6abf4b) | ↗ +2 |
| -1 to +1 (flat) | → | Gray (#94a3b8) | → 0 |
| -1 to -2 | ↘ | Light red (#e57373) | ↘ -2 |
| -3 or more | ↓ | Red (#e74c3c) | ↓ -7 |
| No previous year data | — | Gray | — |

**Important:** The arrow direction reflects whether this is **good or bad for the area**, not just whether the number went up. For `negative` direction indicators (crime rate, debt rate), a *decrease* in percentile is actually good — but we already store normalized values where higher = better. So:

- Use the **normalized_value** (already direction-corrected) for trend comparison, not raw_value
- Or simpler: use the raw percentile rank, and let the arrow color follow the indicator's `direction`:
  - `positive` indicator: percentile went up → green. Down → red.
  - `negative` indicator: percentile went up → red (higher crime rank = worse). Down → green.
  - `neutral` indicator: always gray arrow.

Actually — the cleanest approach: always compare **directed percentile** (the value after direction is applied in scoring). If the system already computes `directed_value = direction === 'negative' ? 1 - normalized : normalized`, compare those. Then ↑ always means "better for the area" regardless of indicator type.

### Tooltip (Current)

```
┌─────────────────────────────────────┐
│ Median Income                       │
│                                     │
│ Description text about what this    │
│ indicator measures...               │
│                                     │
│ National average: ~265 000 kr       │
│ Source: SCB   Data from: 2024       │
└─────────────────────────────────────┘
```

### Tooltip (After)

```
┌─────────────────────────────────────┐
│ Median Income                       │
│                                     │
│ Description text about what this    │
│ indicator measures...               │
│                                     │
│ ╭───────────────────────────╮       │
│ │  ▁▂▃▃▄▅▆█                │       │
│ │ '19 '20 '21 '22 '23 '24  │       │
│ ╰───────────────────────────╯       │
│ 68→71→73→74→75→78:e percentilen     │
│                                     │
│ National average: ~265 000 kr       │
│ Source: SCB   Data from: 2024       │
└─────────────────────────────────────┘
```

The sparkline shows the percentile trajectory. Below it, the actual percentile values per year. This gives full context: "the area has been steadily climbing in income rank for 6 years" vs "it jumped this year but was flat before."

---

## Step 1: Backend — Serve Historical Percentiles

### 1.1 Extend the Location/DeSO API Response

The API that serves indicator data for the sidebar needs to include historical values. Currently it returns something like:

```json
{
  "slug": "median_income",
  "raw_value": 287000,
  "normalized_value": 0.78,
  "percentile": 78
}
```

Extend to:

```json
{
  "slug": "median_income",
  "raw_value": 287000,
  "normalized_value": 0.78,
  "percentile": 78,
  "direction": "positive",
  "trend": {
    "years": [2019, 2020, 2021, 2022, 2023, 2024],
    "percentiles": [68, 71, 73, 74, 75, 78],
    "raw_values": [241000, 251000, 259000, 268000, 275000, 287000],
    "change_1y": 3,
    "change_3y": 5,
    "change_5y": 10
  }
}
```

### 1.2 Query

```php
// In whatever service builds the indicator response for a DeSO

private function getIndicatorWithTrend(string $desoCode, Indicator $indicator, int $currentYear): array
{
    // Fetch all years for this indicator + DeSO
    $history = IndicatorValue::where('deso_code', $desoCode)
        ->where('indicator_id', $indicator->id)
        ->whereNotNull('raw_value')
        ->orderBy('year')
        ->get(['year', 'raw_value', 'normalized_value']);

    $current = $history->firstWhere('year', $currentYear);
    $prevYear = $history->firstWhere('year', $currentYear - 1);

    // Percentile = normalized_value × 100 (already 0-1 from rank normalization)
    $currentPercentile = $current ? round($current->normalized_value * 100) : null;
    $prevPercentile = $prevYear ? round($prevYear->normalized_value * 100) : null;

    return [
        'slug' => $indicator->slug,
        'name' => $indicator->name,
        'raw_value' => $current?->raw_value,
        'normalized_value' => $current?->normalized_value,
        'percentile' => $currentPercentile,
        'direction' => $indicator->direction,
        'unit' => $indicator->unit,
        'trend' => [
            'years' => $history->pluck('year')->values(),
            'percentiles' => $history->pluck('normalized_value')
                ->map(fn ($v) => round($v * 100))->values(),
            'raw_values' => $history->pluck('raw_value')->values(),
            'change_1y' => ($currentPercentile !== null && $prevPercentile !== null)
                ? $currentPercentile - $prevPercentile
                : null,
            'change_3y' => $this->computeChange($history, $currentYear, 3),
            'change_5y' => $this->computeChange($history, $currentYear, 5),
        ],
    ];
}

private function computeChange(Collection $history, int $currentYear, int $span): ?int
{
    $current = $history->firstWhere('year', $currentYear);
    $past = $history->firstWhere('year', $currentYear - $span);

    if (!$current || !$past) return null;

    return round($current->normalized_value * 100) - round($past->normalized_value * 100);
}
```

### 1.3 Performance Note

This adds one query per indicator per DeSO (fetching ~6 rows each). With ~15 scored indicators, that's 15 extra queries returning ~6 rows each = ~90 rows total. Negligible.

Or batch it: one query fetching all indicator_values for this DeSO across all years, then group in PHP:

```php
$allHistory = IndicatorValue::where('deso_code', $desoCode)
    ->whereIn('indicator_id', $activeIndicatorIds)
    ->whereNotNull('raw_value')
    ->orderBy('year')
    ->get()
    ->groupBy('indicator_id');
```

One query, ~90-150 rows. Fast.

---

## Step 2: Frontend — Trend Arrow Component

### 2.1 TrendArrow Component

```tsx
// resources/js/Components/TrendArrow.tsx

interface TrendArrowProps {
    change: number | null;
    direction: 'positive' | 'negative' | 'neutral';
    size?: 'sm' | 'md';
}

export function TrendArrow({ change, direction, size = 'sm' }: TrendArrowProps) {
    if (change === null || direction === 'neutral') {
        return <span className="text-muted-foreground text-xs">—</span>;
    }

    // For negative indicators (crime, debt), flip the interpretation
    // A percentile decrease in crime = good = green arrow
    const effectiveChange = direction === 'negative' ? -change : change;

    const { arrow, color } = getArrowStyle(effectiveChange);
    const sign = change > 0 ? '+' : '';

    return (
        <span
            className={cn(
                'inline-flex items-center gap-0.5 font-medium tabular-nums',
                size === 'sm' ? 'text-xs' : 'text-sm'
            )}
            style={{ color }}
            title={`${sign}${change} percentilpoäng vs förra året`}
        >
            {arrow} {sign}{change}
        </span>
    );
}

function getArrowStyle(effectiveChange: number): { arrow: string; color: string } {
    if (effectiveChange >= 3) return { arrow: '↑', color: '#27ae60' };
    if (effectiveChange >= 1) return { arrow: '↗', color: '#6abf4b' };
    if (effectiveChange >= -1) return { arrow: '→', color: '#94a3b8' };
    if (effectiveChange >= -3) return { arrow: '↘', color: '#e57373' };
    return { arrow: '↓', color: '#e74c3c' };
}
```

### 2.2 Usage in Indicator Bar

```tsx
// In the sidebar indicator row

<div className="flex items-center justify-between">
    <span className="text-sm">{indicator.name}</span>
    <div className="flex items-center gap-2">
        <span className="text-sm text-muted-foreground">
            {indicator.percentile}:e percentilen
        </span>
        <TrendArrow
            change={indicator.trend.change_1y}
            direction={indicator.direction}
        />
    </div>
</div>
<IndicatorBar percentile={indicator.percentile} direction={indicator.direction} />
<span className="text-xs text-muted-foreground">
    ({formatValue(indicator.raw_value, indicator.unit)})
</span>
```

---

## Step 3: Frontend — Tooltip Sparkline

### 3.1 Sparkline Component

A tiny SVG line chart. No axes, no labels, no interactivity — just a line showing the trajectory.

```tsx
// resources/js/Components/Sparkline.tsx

interface SparklineProps {
    values: number[];        // Percentile values per year
    years: number[];
    width?: number;
    height?: number;
    color?: string;          // Line color — use trend direction
}

export function Sparkline({
    values,
    years,
    width = 200,
    height = 40,
    color = '#64748b',
}: SparklineProps) {
    if (values.length < 2) return null;

    const padding = 4;
    const w = width - padding * 2;
    const h = height - padding * 2;

    const min = Math.min(...values);
    const max = Math.max(...values);
    const range = max - min || 1; // Avoid division by zero for flat lines

    const points = values.map((v, i) => {
        const x = padding + (i / (values.length - 1)) * w;
        const y = padding + h - ((v - min) / range) * h;
        return `${x},${y}`;
    });

    // Determine overall trend color
    const trendColor = values[values.length - 1] > values[0]
        ? '#27ae60'  // Trending up
        : values[values.length - 1] < values[0]
            ? '#e74c3c'  // Trending down
            : '#94a3b8'; // Flat

    return (
        <div className="space-y-1">
            <svg width={width} height={height} className="block">
                {/* Subtle background area fill */}
                <polygon
                    points={`${padding},${padding + h} ${points.join(' ')} ${padding + w},${padding + h}`}
                    fill={trendColor}
                    opacity={0.08}
                />
                {/* Line */}
                <polyline
                    points={points.join(' ')}
                    fill="none"
                    stroke={color || trendColor}
                    strokeWidth={2}
                    strokeLinecap="round"
                    strokeLinejoin="round"
                />
                {/* Endpoint dot */}
                <circle
                    cx={parseFloat(points[points.length - 1].split(',')[0])}
                    cy={parseFloat(points[points.length - 1].split(',')[1])}
                    r={3}
                    fill={color || trendColor}
                />
            </svg>
            {/* Year labels */}
            <div className="flex justify-between text-[10px] text-muted-foreground" style={{ width }}>
                {years.map((y, i) => (
                    <span key={y}>
                        {i === 0 || i === years.length - 1 ? `'${String(y).slice(2)}` : ''}
                    </span>
                ))}
            </div>
            {/* Percentile journey */}
            <div className="text-[10px] text-muted-foreground">
                {values.join(' → ')}:e percentilen
            </div>
        </div>
    );
}
```

### 3.2 Add to Existing Tooltip

The indicator info tooltips (the ℹ️ icon popovers) already show description, source, national average. Add the sparkline between the description and the metadata:

```tsx
// In the tooltip/popover content

<div className="space-y-3 p-3 max-w-xs">
    <h4 className="font-semibold">{indicator.name}</h4>
    <p className="text-sm text-muted-foreground">{indicator.description}</p>

    {/* NEW: Sparkline section */}
    {indicator.trend.percentiles.length >= 2 && (
        <div className="border-t border-b py-2">
            <Sparkline
                values={indicator.trend.percentiles}
                years={indicator.trend.years}
                width={220}
                height={36}
            />
        </div>
    )}

    <div className="text-xs text-muted-foreground italic">
        {indicator.methodology_note}
    </div>
    <div className="text-xs text-muted-foreground">
        National average: {indicator.national_average}
    </div>
    <div className="text-xs text-muted-foreground">
        Source: {indicator.source_name} · Data from: {indicator.latest_year}
    </div>
</div>
```

---

## Step 4: Handle Edge Cases

### 4.1 Indicators With No History

POI-derived indicators (grocery_density, transit_stop_density, etc.) only have 2025 data. No sparkline, no arrow:

```tsx
{indicator.trend.percentiles.length >= 2
    ? <TrendArrow change={indicator.trend.change_1y} direction={indicator.direction} />
    : <span className="text-xs text-muted-foreground">—</span>
}
```

### 4.2 Indicators Starting Later (Skolverket)

School indicators start at 2021. They'll have 4-5 data points instead of 6. The sparkline handles variable-length arrays — it just renders fewer points. The 1y change still works.

### 4.3 Direction-Corrected Arrows for Negative Indicators

Crime rate goes DOWN → area gets BETTER → green arrow.

The `TrendArrow` component handles this via the `direction` prop. When direction is `negative`, the effective change is flipped before choosing arrow color. The displayed number still shows the raw percentile change (e.g., "↑ -5" would be confusing), so:

- For `positive` indicators: `↑ +3` means "percentile went up 3 points (good)"
- For `negative` indicators: `↓ -3` with GREEN arrow means "crime percentile dropped 3 points (good)"

Wait — showing a green down-arrow with a negative number is confusing. Better approach:

**Show the "goodness" change, not the raw percentile change:**

For all indicators, compute `directed_change`:
- `positive`: `directed_change = change` (percentile went up = good)
- `negative`: `directed_change = -change` (percentile went DOWN = good, so flip sign to show as positive)

Then always show the directed change with matching arrow:
- Crime percentile dropped from 65 to 60: `change = -5`, `directed_change = +5` → `↑ +5` (green) — "crime improved by 5 points"
- Crime percentile went from 60 to 65: `change = +5`, `directed_change = -5` → `↓ -5` (red) — "crime worsened by 5 points"

This way the arrow and number always mean the same thing: green ↑ = "this got better for the area."

### 4.4 Vulnerability Flag

`vulnerability_flag` uses the same 2025 classification retroactively for all years (as noted in the completeness report). Its sparkline would be flat — all years show the same value. Either:
- Skip the sparkline for this indicator
- Or show it but it'll just be a flat line (which honestly communicates "no historical data" accurately)

Prefer: skip it. Show "—" for trend.

---

## Step 5: Composite Score Trend

The composite score in the sidebar header already shows a trend badge. Make sure it uses real historical data now:

```
┌─────────────────────────────────┐
│  Södermalm 0143                 │
│  Stockholm, Stockholms län      │
│                                 │
│       72                        │  ← Big score number
│  Stabil / positiv utsikt       │
│       ↑ +3.2 vs 2023           │  ← 1-year composite score change
│                                 │
│  ▁▂▃▄▅▆▇█  (sparkline)         │  ← 6-year composite trajectory
│  '19 '20 '21 '22 '23 '24       │
└─────────────────────────────────┘
```

The composite score sparkline comes from `composite_scores` table which now has rows for 2019-2024.

```php
$scoreHistory = CompositeScore::where('deso_code', $desoCode)
    ->orderBy('year')
    ->get(['year', 'score', 'trend_1y']);
```

---

## Verification

- [ ] Every indicator with 2+ years of data shows a trend arrow next to the percentile
- [ ] Arrows are colored correctly: green = better for the area, red = worse
- [ ] Negative indicators (crime, debt) show green when their percentile *drops*
- [ ] POI indicators show "—" instead of an arrow (single year only)
- [ ] Clicking the ℹ️ tooltip shows a sparkline with year labels
- [ ] Sparkline renders correctly for 2 points (Skolverket 2024-2025), 5 points, and 6 points
- [ ] Composite score at the top of the sidebar shows a sparkline of 2019-2024
- [ ] Flat trends show gray → arrow and flat sparkline (not hidden)
- [ ] Vulnerability flag shows "—" (no real historical variation)
- [ ] All numbers are in percentile points, not raw values ("+3" means 3 percentile points, not 3%)

---

## What NOT to Do

- **DO NOT show raw value changes in the arrow.** "Income went up 12,000 SEK" is meaningless without context. "+3 percentile points" is universally comparable across indicators.
- **DO NOT show both 1y and 5y arrows.** One arrow (1y) keeps it clean. The sparkline in the tooltip gives the long view.
- **DO NOT add interactivity to the sparkline.** No hover states, no click handlers. It's a tiny glyph that communicates trajectory at a glance. Keep it a simple SVG.
- **DO NOT fetch historical data in a separate API call.** Bundle it with the existing indicator response — it's ~90 extra rows, negligible overhead.
- **DO NOT show trend arrows for indicators with `neutral` direction.** Population and foreign_background_pct are context-only — no "better/worse" interpretation.