# TASK: Sidebar Teaser v3 — Free Preview Indicators with Real Values

## Context

Follow-up to sidebar teaser v2. The current version shows category headers + anonymous gray bars + data scale stats. It communicates depth but feels sterile — there's nothing to grab onto. The user sees "Trygghet & brottslighet" with three gray lines and thinks "ok" instead of "wait, what?"

The fix: show **two real indicator names with real values** per category, free. Then the remaining indicators stay as gray bars. The free values create tension — partial information that raises questions only the full report answers.

Example:
```
🛡️ TRYGGHET & BROTTSLIGHET

Upplevd trygghet (NTU)     ▓▓▓▓▓▓▓▓░░  42:a
Våldsbrott                 ▓▓░░░░░░░░  18:e
░░░░░░░░░░░░░░░░░░░
░░░░░░░░░░░░░░

Trygghetsbetyg baserat på brottsstatistik
från 290 kommuner och 65 utsatta områden.
```

"42nd percentile for perceived safety and 18th for violent crime... that's bad. What about property crime? What about the police vulnerability classification? I need to see the rest."

That's the conversion moment. Not a locked door — a cracked-open door.

## Depends On

- Sidebar teaser v2 (completed — category sections with gray bars and stat lines)
- Indicator values in the database (indicator_values table populated)

---

## Step 1: Backend — `is_free_preview` Flag

### 1.1 Migration

Add a flag to the indicators table:

```php
Schema::table('indicators', function (Blueprint $table) {
    $table->boolean('is_free_preview')->default(false)->after('is_active');
});
```

### 1.2 Seeder Update

Set `is_free_preview = true` for the two most compelling indicators per category. These are the "hook" indicators — the ones that make the user want to see more.

**Selection criteria for free preview indicators:**
- Immediately understandable (no jargon)
- Emotionally resonant (safety, money, schools)
- Creates curiosity when paired (high income + low safety = "what's going on?")

```php
// In the indicator seeder or a dedicated migration

$freePreviewSlugs = [
    // Safety — "am I safe here?"
    'perceived_safety_ntu',    // "Upplevd trygghet" — everyone understands this
    'violent_crime_rate',      // "Våldsbrott" — scary, visceral, drives clicks

    // Economy — "can I afford this? is it getting better?"
    'median_income',           // "Medianinkomst" — the number everyone wants
    'employment_rate',         // "Sysselsättningsgrad" — stability signal

    // Education — "are the schools good?"
    'school_merit_value_avg',  // "Meritvärde" — THE metric Swedish parents know
    'school_teacher_certification_avg',  // "Lärarbehörighet" — parents care deeply

    // Proximity — "what's nearby?"
    'prox_transit',            // "Kollektivtrafik" — daily life essential
    'prox_grocery',            // "Livsmedel" — daily life essential
];

Indicator::whereIn('slug', $freePreviewSlugs)
    ->update(['is_free_preview' => true]);
```

### 1.3 Why These Specific Indicators

| Category | Free Preview 1 | Why | Free Preview 2 | Why |
|---|---|---|---|---|
| Safety | Upplevd trygghet (NTU) | "Perceived safety" is personal — it's how PEOPLE feel, not just stats | Våldsbrott | Violent crime is the gut-punch. Nobody scrolls past this. |
| Economy | Medianinkomst | Everyone knows what median income means. Immediate context. | Sysselsättningsgrad | "Are people here working?" Simple, powerful. |
| Education | Meritvärde (skolor) | THE school metric in Sweden. Parents live and die by this number. | Lärarbehörighet | "Are the teachers even qualified?" Provocative. |
| Proximity | Kollektivtrafik | "Can I get to work?" Most practical daily concern. | Livsmedel | "Is there a grocery store?" Basic livability. |

**What's left locked (examples per category):**
- Safety: property crime, total crime rate, police vulnerability classification
- Economy: low economic standard, debt rate, eviction rate, debt amount
- Education: goal achievement rate, education levels (post-secondary, below secondary)
- Proximity: school distance, green space, positive POIs, negative POIs

The locked indicators are the "and what else?" that drives conversion.

---

## Step 2: API Response — Include Free Values

### 2.1 Updated Preview Endpoint

```php
// In the location preview controller/service

$freePreviewValues = IndicatorValue::query()
    ->join('indicators', 'indicators.id', '=', 'indicator_values.indicator_id')
    ->where('indicator_values.deso_code', $deso->deso_code)
    ->where('indicators.is_active', true)
    ->where('indicators.is_free_preview', true)
    ->whereNotNull('indicator_values.raw_value')
    ->orderBy('indicators.display_order')
    ->select([
        'indicators.slug',
        'indicators.name',
        'indicators.unit',
        'indicators.direction',
        'indicators.category',
        'indicator_values.raw_value',
        'indicator_values.normalized_value',
        'indicator_values.year',
    ])
    ->get()
    ->map(fn($iv) => [
        'slug' => $iv->slug,
        'name' => $iv->name,
        'unit' => $iv->unit,
        'direction' => $iv->direction,
        'category' => $iv->category,
        'raw_value' => round($iv->raw_value, 1),
        'percentile' => $iv->normalized_value !== null
            ? round($iv->normalized_value * 100)
            : null,
        'year' => $iv->year,
    ]);
```

### 2.2 Updated Category Shape

Each category now includes its free preview indicators:

```php
'categories' => [
    [
        'slug' => 'safety',
        'label' => 'Trygghet & brottslighet',
        'emoji' => '🛡️',
        'icon' => 'shield',
        'stat_line' => '...',
        'indicator_count' => 5,         // Total indicators in this category
        'locked_count' => 3,            // How many are behind paywall
        'free_indicators' => [          // Real values, free
            [
                'slug' => 'perceived_safety_ntu',
                'name' => 'Upplevd trygghet',
                'raw_value' => 4.2,
                'percentile' => 42,
                'unit' => 'index',
                'direction' => 'positive',
            ],
            [
                'slug' => 'violent_crime_rate',
                'name' => 'Våldsbrott',
                'raw_value' => 12.8,
                'percentile' => 18,
                'unit' => 'per_1000',
                'direction' => 'negative',
            ],
        ],
    ],
    // ... other categories
],
```

---

## Step 3: Frontend — Mixed Free + Locked Rows

### 3.1 Free Indicator Row

```tsx
function FreeIndicatorRow({ indicator }: {
    indicator: {
        name: string;
        raw_value: number;
        percentile: number | null;
        unit: string;
        direction: string;
    };
}) {
    const percentile = indicator.percentile ?? 0;

    // Color based on direction + percentile
    // Positive direction: high percentile = green, low = red
    // Negative direction: high percentile = red (high crime = bad), low = green
    const isGood = indicator.direction === 'positive'
        ? percentile >= 50
        : percentile < 50;

    const barColor = isGood ? 'bg-emerald-500' : 'bg-amber-500';
    const textColor = isGood ? 'text-emerald-700' : 'text-amber-700';

    return (
        <div className="flex items-center gap-3 py-1.5">
            <span className="text-sm flex-1 min-w-0 truncate">
                {indicator.name}
            </span>
            <div className="w-20 h-2 bg-muted rounded-full overflow-hidden shrink-0">
                <div
                    className={`h-full rounded-full ${barColor}`}
                    style={{ width: `${percentile}%` }}
                />
            </div>
            <span className={`text-xs font-medium w-10 text-right shrink-0 ${textColor}`}>
                {percentile !== null ? `${ordinal(percentile)}` : '—'}
            </span>
        </div>
    );
}

// Swedish ordinal: "42:a", "78:e", "1:a"
function ordinal(n: number): string {
    if (n === 1 || n === 2) return `${n}:a`;
    return `${n}:e`;
}
```

### 3.2 Locked Indicator Rows (The Remaining Ones)

```tsx
function LockedIndicatorRows({ count }: { count: number }) {
    // Show `count` gray bars with varying widths
    const barWidths = useMemo(() => {
        return Array.from({ length: count }, (_, i) => {
            const base = ((i * 37 + 13) % 35) + 55; // 55-90%
            return `${base}%`;
        });
    }, [count]);

    return (
        <div className="space-y-2 mt-2 opacity-50">
            {barWidths.map((width, i) => (
                <div key={i} className="flex items-center gap-3 py-1">
                    <div className="h-3 bg-muted rounded flex-1" style={{ maxWidth: '45%' }} />
                    <div className="w-20 h-2 bg-muted rounded-full shrink-0" />
                    <div className="w-10 h-3 bg-muted rounded shrink-0" />
                </div>
            ))}
        </div>
    );
}
```

This mimics the layout of `FreeIndicatorRow` — a text placeholder, a bar placeholder, and a value placeholder — so the locked rows look like the free rows but grayed out. The structural similarity makes the paywall feel like a continuation, not a wall.

### 3.3 "And X More" Label

```tsx
function LockedCountLabel({ count }: { count: number }) {
    return (
        <p className="text-xs text-muted-foreground mt-1 flex items-center gap-1">
            <Lock className="h-3 w-3" />
            + {count} indikatorer i rapporten
        </p>
    );
}
```

### 3.4 Updated Category Section

```tsx
function CategorySection({ category }: { category: CategoryData }) {
    return (
        <div className="pt-5 first:pt-0">
            {/* Category header */}
            <div className="flex items-center gap-2 mb-3">
                <span className="text-base">{category.emoji}</span>
                <h3 className="text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                    {category.label}
                </h3>
            </div>

            {/* Free preview indicators — real names, real values */}
            {category.free_indicators.map(indicator => (
                <FreeIndicatorRow key={indicator.slug} indicator={indicator} />
            ))}

            {/* Locked indicators — gray bars matching the layout */}
            {category.locked_count > 0 && (
                <>
                    <LockedIndicatorRows count={Math.min(category.locked_count, 3)} />
                    <LockedCountLabel count={category.locked_count} />
                </>
            )}

            {/* Data scale stat line */}
            <p className="text-xs text-muted-foreground leading-relaxed mt-3">
                {category.stat_line}
            </p>
        </div>
    );
}
```

### 3.5 Visual Result

```
🛡️ TRYGGHET & BROTTSLIGHET

Upplevd trygghet          ▓▓▓▓▓▓▓▓░░  42:a
Våldsbrott                ▓▓░░░░░░░░  18:e
████████████              ░░░░░░░░░░  ███
██████████████████        ░░░░░░░░░░  ███
█████████████             ░░░░░░░░░░  ███
🔒 + 3 indikatorer i rapporten

Trygghetsbetyg baserat på brottsstatistik
från 290 kommuner och 65 utsatta områden.

📊 EKONOMI & ARBETSMARKNAD

Medianinkomst             ▓▓▓▓▓▓▓▓▓░  78:e
Sysselsättningsgrad       ▓▓▓▓▓▓░░░░  61:a
████████████████          ░░░░░░░░░░  ███
████████████              ░░░░░░░░░░  ███
█████████████████████     ░░░░░░░░░░  ███
██████████████            ░░░░░░░░░░  ███
🔒 + 4 indikatorer i rapporten

Ekonomisk analys från 6 indikatorer — inkomst,
sysselsättning, skuldsättning och ekonomisk standard.

🎓 UTBILDNING

Meritvärde (skolor)       ▓▓▓▓▓▓▓▓▓░  91:a
Lärarbehörighet           ▓▓▓▓▓▓▓░░░  68:e
█████████████████████     ░░░░░░░░░░  ███
████████████████          ░░░░░░░░░░  ███
██████████████████        ░░░░░░░░░░  ███
🔒 + 3 indikatorer i rapporten

Skolanalys baserad på 7 507 grundskolor med
meritvärden, måluppfyllelse och lärarbehörighet.

📍 NÄRHETSANALYS

Kollektivtrafik           ▓▓▓▓▓▓▓▓░░  82:a
Livsmedel                 ▓▓▓▓▓▓▓░░░  71:a
█████████████████████     ░░░░░░░░░░  ███
██████████████████        ░░░░░░░░░░  ███
████████████████          ░░░░░░░░░░  ███
██████████████████████    ░░░░░░░░░░  ███
🔒 + 4 indikatorer i rapporten

Vi analyserade 254 servicepunkter inom 2 km —
kollektivtrafik, grönområden, mataffärer och mer.
```

The free rows POP because they have color (green/amber bars) against the gray locked rows. The contrast is the sales pitch.

---

## Step 4: Admin Control

### 4.1 Admin Dashboard Update

In the indicator management table, add a toggle column for `is_free_preview`:

```tsx
// In Admin/Indicators.tsx
<TableHead>Fri förhandsgranskning</TableHead>
// ...
<TableCell>
    <Switch
        checked={indicator.is_free_preview}
        onCheckedChange={(checked) => updateIndicator(indicator.id, { is_free_preview: checked })}
    />
</TableCell>
```

### 4.2 Validation: Max 2 Per Category

The admin shouldn't be able to set more than 2 free preview indicators per category. Add a backend validation:

```php
// In AdminIndicatorController::update

public function update(Request $request, Indicator $indicator)
{
    $validated = $request->validate([
        'is_free_preview' => 'sometimes|boolean',
        // ... other fields
    ]);

    if (($validated['is_free_preview'] ?? false) === true) {
        $currentFreeCount = Indicator::where('category', $indicator->category)
            ->where('is_free_preview', true)
            ->where('id', '!=', $indicator->id)
            ->count();

        if ($currentFreeCount >= 2) {
            return back()->withErrors([
                'is_free_preview' => "Max 2 fria förhandsgranskningsindikatorer per kategori. Inaktivera en annan i '{$indicator->category}' först.",
            ]);
        }
    }

    $indicator->update($validated);

    return back()->with('success', 'Indikator uppdaterad.');
}
```

### 4.3 Admin Visual: Free Preview Count

In the weight allocation bar at the top of the admin page, add a count:

```
Fri förhandsgranskning: 8/8 indikatorer (2 per kategori)
```

---

## Step 5: Handle Missing Values

Some DeSOs may not have data for a free preview indicator. Handle gracefully:

```tsx
// In CategorySection
{category.free_indicators.length > 0 ? (
    category.free_indicators.map(indicator => (
        <FreeIndicatorRow key={indicator.slug} indicator={indicator} />
    ))
) : (
    // No free indicators have data for this DeSO — show all locked
    <LockedIndicatorRows count={Math.min(category.indicator_count, 3)} />
)}
```

If a free preview indicator has `raw_value = null` for this DeSO, the backend should exclude it from `free_indicators` and increment `locked_count`. The frontend never shows "Medianinkomst — —" — either it has a value and shows it, or it's hidden in the gray bars.

---

## Step 6: Value Formatting

### 6.1 Format Function

```tsx
function formatIndicatorValue(value: number, unit: string): string {
    switch (unit) {
        case 'SEK':
            return `${Math.round(value).toLocaleString('sv-SE')} kr`;
        case 'percent':
            return `${value.toFixed(1)}%`;
        case 'per_1000':
            return `${value.toFixed(1)}/1000`;
        case 'points':
            return `${Math.round(value)}`;
        case 'index':
            return value.toFixed(1);
        default:
            return value.toFixed(1);
    }
}
```

### 6.2 Tooltip on Hover

Free indicator rows show a tooltip with the raw value on hover:

```tsx
<div className="..." title={`${indicator.name}: ${formatIndicatorValue(indicator.raw_value, indicator.unit)}`}>
```

The percentile is shown inline (e.g., "42:a"). The raw value is in the tooltip (e.g., "Upplevd trygghet: 4.2"). This keeps the row compact while still providing the actual number.

The full report shows both inline — percentile AND raw value. Another reason to unlock.

---

## Verification

### Visual
- [ ] Each category shows exactly 2 real indicator rows with colored bars and percentile values
- [ ] Below the real rows, gray locked rows match the layout structure (text placeholder + bar + value placeholder)
- [ ] "🔒 + N indikatorer i rapporten" shows correct locked count per category
- [ ] Free indicator bars are colored (green for good, amber for bad) — contrasts with gray locked bars
- [ ] Data scale stat line still appears below each category
- [ ] Swedish ordinal formatting works: 1:a, 2:a, 3:e, 42:a, 78:e

### Data
- [ ] Free preview values are REAL — match what the full report would show
- [ ] Percentiles are correct (match normalized_value × 100)
- [ ] Direction logic correct: high "Upplevd trygghet" = green (positive direction), high "Våldsbrott" = amber (negative direction)
- [ ] DeSOs with missing data for a free indicator: indicator excluded, not shown with "—"

### Admin
- [ ] `is_free_preview` toggle visible in admin indicator table
- [ ] Max 2 per category enforced — can't enable a third without disabling one
- [ ] Changing which indicators are free preview updates the sidebar immediately (after page refresh)

### Edge Cases
- [ ] DeSO with no data for either free indicator in a category → category shows only locked bars (no empty free rows)
- [ ] DeSO with data for only 1 of 2 free indicators → shows 1 free row + locked bars
- [ ] Category with 0 locked indicators (only 2 total, both free) → no gray bars, no "🔒 +" label

---

## What NOT to Do

- **DO NOT blur values.** Blurred text says "we're hiding this from you" — adversarial. Real values + locked remaining = "here's a taste, want more?" — generous.
- **DO NOT show raw values inline for free indicators.** Keep the row compact: name + bar + percentile. Raw value in tooltip. The full report shows everything — that's part of the upsell.
- **DO NOT hardcode which indicators are free.** The `is_free_preview` flag is admin-controlled. You'll want to A/B test different combinations to optimize conversion.
- **DO NOT show more than 2 free per category.** Two creates curiosity. Three starts to feel like you're giving away the product. One feels stingy. Two is the sweet spot.
- **DO NOT change the free values based on whether they look "good" or "bad" for the area.** Always show the same two indicators regardless of whether the percentile is flattering. Selective data display destroys trust.
- **DO NOT add a "sign up to see these free" gate.** The free values are free for everyone. No email, no account, no cookie wall. They see the score + 8 real indicator values + locked rest. That's the free tier.