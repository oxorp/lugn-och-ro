# TASK: Centralize Score Colors — Single Source of Truth + Red→Green Palette

## Context

Score colors are defined in multiple places right now — scattered across frontend components, backend config references, and documentation. The current palette goes from **deep purple (#4a0072) to green (#1a7a2e)**, which is unconventional. Purple doesn't intuitively mean "bad" to most people. Red does.

This task:
1. Creates a single color config that every consumer reads from
2. Switches the palette from purple→green to **red→green** (universally understood)
3. Updates every place that references score colors

## Why Red→Green

Red = bad, green = good. Everyone knows this. Purple = bad is arbitrary and requires explanation. The legend shouldn't need a legend.

The new palette should still feel modern — not a raw traffic light. We want warm reds through amber/yellow to greens, with enough intermediate stops that the map looks like a proper choropleth, not a Christmas decoration.

---

## Step 1: Define the Canonical Color Scale

### 1.1 The Config File

Create a single source of truth:

```php
// config/score_colors.php

return [
    /*
    |--------------------------------------------------------------------------
    | Score Color Scale
    |--------------------------------------------------------------------------
    |
    | Continuous gradient stops for the 0–100 composite score.
    | Used by: map polygon fill, sidebar score badge, indicator bars,
    | school markers, legend, report PDF, admin dashboard.
    |
    | Format: score threshold => hex color
    | The frontend interpolates between these stops.
    |
    */

    'gradient_stops' => [
        0   => '#c0392b',  // Deep red — high risk
        25  => '#e74c3c',  // Red — elevated risk
        40  => '#f39c12',  // Amber/orange — mixed signals (low end)
        50  => '#f1c40f',  // Yellow — mixed signals (mid)
        60  => '#f1c40f',  // Yellow — mixed signals (high end)
        75  => '#27ae60',  // Green — positive outlook
        100 => '#1a7a2e',  // Deep green — strong growth
    ],

    /*
    |--------------------------------------------------------------------------
    | Score Labels & Thresholds
    |--------------------------------------------------------------------------
    |
    | Human-readable labels for score ranges.
    | Swedish labels are the primary display language.
    |
    */

    'labels' => [
        ['min' => 80, 'max' => 100, 'label_sv' => 'Starkt tillväxtområde',  'label_en' => 'Strong Growth Area',      'color' => '#1a7a2e'],
        ['min' => 60, 'max' => 79,  'label_sv' => 'Stabil / positiv utsikt', 'label_en' => 'Stable / Positive Outlook', 'color' => '#27ae60'],
        ['min' => 40, 'max' => 59,  'label_sv' => 'Blandade signaler',       'label_en' => 'Mixed Signals',             'color' => '#f1c40f'],
        ['min' => 20, 'max' => 39,  'label_sv' => 'Förhöjd risk',            'label_en' => 'Elevated Risk',             'color' => '#e74c3c'],
        ['min' => 0,  'max' => 19,  'label_sv' => 'Hög risk / vikande',      'label_en' => 'High Risk / Declining',     'color' => '#c0392b'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Special Colors
    |--------------------------------------------------------------------------
    */

    'no_data' => '#d5d5d5',           // Gray for DeSOs with no score
    'no_data_border' => '#bbbbbb',     // Dashed border for no-data areas
    'selected_border' => '#1e3a5f',    // Border for selected DeSO polygon

    /*
    |--------------------------------------------------------------------------
    | School Marker Colors
    |--------------------------------------------------------------------------
    |
    | School markers on the map are colored by meritvärde.
    | Same red→green logic but with different thresholds.
    |
    */

    'school_markers' => [
        'high'    => '#27ae60',  // Meritvärde > 230
        'medium'  => '#f1c40f',  // 200–230
        'low'     => '#e74c3c',  // < 200
        'no_data' => '#999999',  // No meritvärde available
    ],

    /*
    |--------------------------------------------------------------------------
    | Indicator Bar Colors
    |--------------------------------------------------------------------------
    |
    | Used in sidebar indicator bars and report pages.
    | "good" = this indicator contributes positively to the score.
    | "bad" = this indicator pulls the score down.
    |
    */

    'indicator_bar' => [
        'good' => '#27ae60',    // Emerald green
        'bad'  => '#e74c3c',    // Red
        'neutral' => '#94a3b8', // Slate gray
    ],
];
```

### 1.2 Share with Frontend via Inertia

Make the colors available to every page without extra API calls:

```php
// In HandleInertiaRequests middleware

public function share(Request $request): array
{
    return [
        ...parent::share($request),
        'scoreColors' => config('score_colors'),
    ];
}
```

### 1.3 TypeScript Types

```tsx
// resources/js/types/score-colors.ts

export interface ScoreColorConfig {
    gradient_stops: Record<number, string>;
    labels: Array<{
        min: number;
        max: number;
        label_sv: string;
        label_en: string;
        color: string;
    }>;
    no_data: string;
    no_data_border: string;
    selected_border: string;
    school_markers: {
        high: string;
        medium: string;
        low: string;
        no_data: string;
    };
    indicator_bar: {
        good: string;
        bad: string;
        neutral: string;
    };
}
```

---

## Step 2: Frontend Utility — `scoreToColor()`

### 2.1 Interpolation Function

Create a single utility that everything calls:

```tsx
// resources/js/lib/score-colors.ts

import { usePage } from '@inertiajs/react';

/**
 * Linearly interpolate between two hex colors.
 */
function lerpColor(a: string, b: string, t: number): string {
    const parse = (hex: string) => [
        parseInt(hex.slice(1, 3), 16),
        parseInt(hex.slice(3, 5), 16),
        parseInt(hex.slice(5, 7), 16),
    ];

    const [r1, g1, b1] = parse(a);
    const [r2, g2, b2] = parse(b);

    const r = Math.round(r1 + (r2 - r1) * t);
    const g = Math.round(g1 + (g2 - g1) * t);
    const bl = Math.round(b1 + (b2 - b1) * t);

    return `#${[r, g, bl].map(v => v.toString(16).padStart(2, '0')).join('')}`;
}

/**
 * Convert a score (0–100) to a hex color using the config gradient stops.
 * Falls back to hardcoded defaults if config isn't available.
 */
export function scoreToColor(
    score: number | null | undefined,
    stops?: Record<number, string>
): string {
    if (score == null) return '#d5d5d5'; // no_data gray

    // Default stops if not provided
    const gradientStops = stops ?? {
        0: '#c0392b',
        25: '#e74c3c',
        40: '#f39c12',
        50: '#f1c40f',
        60: '#f1c40f',
        75: '#27ae60',
        100: '#1a7a2e',
    };

    const thresholds = Object.keys(gradientStops)
        .map(Number)
        .sort((a, b) => a - b);

    const clamped = Math.max(0, Math.min(100, score));

    // Find the two stops we're between
    for (let i = 0; i < thresholds.length - 1; i++) {
        const lo = thresholds[i];
        const hi = thresholds[i + 1];
        if (clamped >= lo && clamped <= hi) {
            const t = (clamped - lo) / (hi - lo);
            return lerpColor(gradientStops[lo], gradientStops[hi], t);
        }
    }

    // Edge case: return last color
    return gradientStops[thresholds[thresholds.length - 1]];
}

/**
 * Get the score label for a given score.
 */
export function scoreToLabel(
    score: number,
    labels?: ScoreColorConfig['labels']
): string {
    const defaultLabels = [
        { min: 80, max: 100, label_sv: 'Starkt tillväxtområde' },
        { min: 60, max: 79,  label_sv: 'Stabil / positiv utsikt' },
        { min: 40, max: 59,  label_sv: 'Blandade signaler' },
        { min: 20, max: 39,  label_sv: 'Förhöjd risk' },
        { min: 0,  max: 19,  label_sv: 'Hög risk / vikande' },
    ];

    const list = labels ?? defaultLabels;
    const match = list.find(l => score >= l.min && score <= l.max);
    return match?.label_sv ?? 'Okänt';
}

/**
 * Get the color for a school marker based on meritvärde.
 */
export function meritToColor(
    merit: number | null,
    schoolColors?: ScoreColorConfig['school_markers']
): string {
    const colors = schoolColors ?? {
        high: '#27ae60',
        medium: '#f1c40f',
        low: '#e74c3c',
        no_data: '#999999',
    };

    if (merit == null) return colors.no_data;
    if (merit > 230) return colors.high;
    if (merit >= 200) return colors.medium;
    return colors.low;
}

/**
 * Get indicator bar color based on whether this indicator is helping or hurting.
 */
export function indicatorBarColor(
    percentile: number,
    direction: 'positive' | 'negative' | 'neutral',
    barColors?: ScoreColorConfig['indicator_bar']
): string {
    const colors = barColors ?? {
        good: '#27ae60',
        bad: '#e74c3c',
        neutral: '#94a3b8',
    };

    if (direction === 'neutral') return colors.neutral;

    const isGood = direction === 'positive'
        ? percentile >= 50
        : percentile < 50;

    return isGood ? colors.good : colors.bad;
}

/**
 * Generate CSS gradient string for the legend bar.
 */
export function scoreGradientCSS(stops?: Record<number, string>): string {
    const gradientStops = stops ?? {
        0: '#c0392b',
        25: '#e74c3c',
        40: '#f39c12',
        50: '#f1c40f',
        60: '#f1c40f',
        75: '#27ae60',
        100: '#1a7a2e',
    };

    const parts = Object.entries(gradientStops)
        .sort(([a], [b]) => Number(a) - Number(b))
        .map(([pct, color]) => `${color} ${pct}%`);

    return `linear-gradient(to right, ${parts.join(', ')})`;
}
```

### 2.2 React Hook for Easy Access

```tsx
// resources/js/hooks/useScoreColors.ts

import { usePage } from '@inertiajs/react';
import type { ScoreColorConfig } from '@/types/score-colors';

export function useScoreColors(): ScoreColorConfig {
    const { scoreColors } = usePage().props as { scoreColors: ScoreColorConfig };
    return scoreColors;
}
```

---

## Step 3: Find and Replace All Color Consumers

### 3.1 Map Polygon Styling (OpenLayers)

The `DesoMap.tsx` component (or equivalent) currently has a `scoreToColor` function or inline color logic. Replace with the centralized import:

```tsx
// BEFORE (scattered, hardcoded purple-green)
const fill = someLocalScoreToColor(score); // or inline hex interpolation

// AFTER
import { scoreToColor } from '@/lib/score-colors';
const fill = scoreToColor(score);
```

### 3.2 Score Card / Badge in Sidebar

The sidebar score number is colored by the same scale. Currently may have its own color logic:

```tsx
// BEFORE
<span style={{ color: getScoreColor(score) }}>38</span>

// AFTER
import { scoreToColor } from '@/lib/score-colors';
<span style={{ color: scoreToColor(score) }}>38</span>
```

### 3.3 Score Labels

```tsx
// BEFORE
function getLabel(score) { /* hardcoded ranges */ }

// AFTER
import { scoreToLabel } from '@/lib/score-colors';
const label = scoreToLabel(score);
```

### 3.4 School Markers

```tsx
// BEFORE
const markerColor = merit > 230 ? 'green' : merit > 200 ? 'yellow' : 'orange';

// AFTER
import { meritToColor } from '@/lib/score-colors';
const markerColor = meritToColor(merit);
```

### 3.5 Indicator Bars (Sidebar + Report)

```tsx
// BEFORE
const barColor = isGood ? 'bg-emerald-500' : 'bg-amber-500';

// AFTER
import { indicatorBarColor } from '@/lib/score-colors';
const barColor = indicatorBarColor(percentile, direction);
// Use as inline style: style={{ backgroundColor: barColor }}
```

### 3.6 Legend Component

```tsx
// BEFORE
<div style={{ background: 'linear-gradient(to right, #4a0072, #9c1d6e, #f0c040, #6abf4b, #1a7a2e)' }} />

// AFTER
import { scoreGradientCSS } from '@/lib/score-colors';
<div style={{ background: scoreGradientCSS() }} />
```

Legend labels update too:
```tsx
// BEFORE
<span>High Risk</span> [purple] ———— [yellow] ———— [green] <span>Strong Growth</span>

// AFTER
<span>Hög risk</span> [red] ———— [yellow] ———— [green] <span>Stark tillväxt</span>
```

### 3.7 Backend: ScoringService / Score Labels

If the PHP backend generates score labels (e.g., for API responses):

```php
// BEFORE (hardcoded somewhere in ScoringService or a helper)
function scoreLabel(float $score): string {
    return match(true) {
        $score >= 80 => 'Strong Growth Area',
        // ...
    };
}

// AFTER — read from config
function scoreLabel(float $score): string {
    $labels = config('score_colors.labels');
    foreach ($labels as $label) {
        if ($score >= $label['min'] && $score <= $label['max']) {
            return $label['label_sv'];
        }
    }
    return 'Okänt';
}
```

### 3.8 Admin Dashboard

If the admin page shows score previews or indicator colors, update to use the same config. The weight allocation bar and any color previews should read from `scoreColors` shared prop.

---

## Step 4: Update Documentation

### 4.1 project-context.md

Replace the Client-Facing Labels table:

```markdown
| Score | Label | Color |
|---|---|---|
| 80–100 | Starkt tillväxtområde | Deep Green (#1a7a2e) |
| 60–79 | Stabil / positiv utsikt | Green (#27ae60) |
| 40–59 | Blandade signaler | Yellow (#f1c40f) |
| 20–39 | Förhöjd risk | Red (#e74c3c) |
| 0–19 | Hög risk / vikande | Deep Red (#c0392b) |
```

### 4.2 CLAUDE.md

Add to best practices:

```markdown
## Score Colors

All score colors are defined in `config/score_colors.php` — the single source of truth.
Frontend reads from Inertia shared props. Use `scoreToColor()` from `@/lib/score-colors.ts`.
Never hardcode hex values for score-related colors in components.
Palette: red (#c0392b) → amber (#f39c12) → yellow (#f1c40f) → green (#1a7a2e).
```

---

## Step 5: Grep Audit

Run this to find any remaining hardcoded purple-green colors:

```bash
# Old palette hex codes that should no longer appear in source files
grep -rn '#4a0072\|#9c1d6e\|#f0c040\|#6abf4b' resources/js/ app/ --include='*.tsx' --include='*.ts' --include='*.php' --include='*.vue'

# Also search for any local scoreToColor/getScoreColor that isn't the centralized one
grep -rn 'scoreToColor\|getScoreColor\|score.*color\|color.*score' resources/js/ --include='*.tsx' --include='*.ts'
```

Every match should either be the centralized utility or an import of it. Nothing else.

---

## Verification

### Visual
- [ ] Map uses red→yellow→green gradient (no purple anywhere)
- [ ] Legend shows red→green with Swedish labels
- [ ] Sidebar score badge colored by the same scale
- [ ] School markers use red/yellow/green (not orange)
- [ ] Indicator bars use green (good) / red (bad) / gray (neutral)
- [ ] No-data DeSOs are gray with dashed border (unchanged)

### Code
- [ ] `config/score_colors.php` exists and is the only place colors are defined
- [ ] `scoreColors` available in Inertia shared props on every page
- [ ] `@/lib/score-colors.ts` exports: `scoreToColor`, `scoreToLabel`, `meritToColor`, `indicatorBarColor`, `scoreGradientCSS`
- [ ] No old purple hex codes (#4a0072, #9c1d6e) remain in any source file
- [ ] Backend `scoreLabel()` reads from config, not hardcoded match

### Consistency
- [ ] Same score produces same color everywhere: map, sidebar, legend, report, admin
- [ ] Changing a color in `config/score_colors.php` propagates to all consumers after refresh

---

## What NOT to Do

- **DO NOT use pure red (#ff0000) and pure green (#00ff00).** Those are ugly and inaccessible to colorblind users. Use warm reds and muted greens.
- **DO NOT keep any score color hex values outside the config file.** If a component needs a score color, it imports from the utility. No exceptions.
- **DO NOT forget the backend.** PHP helpers that generate labels or colors for API responses must also read from the config.
- **DO NOT add Tailwind color classes for score colors.** Score colors are dynamic (interpolated), so they must use inline `style={{ color: ... }}` or CSS custom properties, not static Tailwind classes like `bg-red-500`.
- **DO NOT make the yellow band too wide.** A map that's mostly yellow is uninformative. The gradient should transition smoothly so you can visually distinguish a 35 from a 55.