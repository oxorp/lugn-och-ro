# TASK: Sidebar Free Preview — Teaser Design & CTA Optimization

## Context

The sidebar currently shows the headline score, urbanity tier, area/proximity breakdown, and the unlock button. Below the button: nothing. Empty space. The user has no idea what they'd be paying for.

This is a conversion problem. The user sees "38.3 — Utmanande område" and thinks "ok, now what?" There's no tension, no curiosity, no reason to click "79 kr" beyond trusting that something useful is behind it.

The fix: show them exactly what the report contains — but locked. Grayed-out indicator bars, blurred school names, redacted numbers. The shape of the data is visible, the values are not. The user sees "there are 14 data points from 4 sources analyzed for this location" and thinks "I need to see those numbers."

This is the Spotify model: you can see the playlist, you can see the song titles, but you can't press play. The free tier is a product demo, not a blank wall.

## Depends On

- Sidebar with score display (completed)
- Indicator data available via API (completed)
- Purchase flow (task-purchase-flow.md — can be implemented in parallel)

---

## Step 1: Data for the Preview

### 1.1 What the API Already Returns

The location endpoint already returns the headline score. We need to extend it to also return the **shape** of the data without the actual values. Specifically:

```php
// In LocationController or DesoController

public function preview(float $lat, float $lng)
{
    // Existing: resolve DeSO, get score
    $deso = $this->resolveDeso($lat, $lng);
    $score = $this->getScore($deso);

    // NEW: get indicator metadata (names, categories, sources — but not values)
    $indicators = Indicator::where('is_active', true)
        ->where('weight', '>', 0)
        ->orderBy('category')
        ->orderBy('display_order')
        ->get()
        ->map(fn($i) => [
            'slug' => $i->slug,
            'name' => $i->name,
            'category' => $i->category,
            'source' => $i->source,
            'unit' => $i->unit,
            'direction' => $i->direction,
            // NO raw_value, NO normalized_value, NO percentile
        ]);

    // NEW: count data points and sources
    $dataPointCount = IndicatorValue::where('deso_code', $deso->deso_code)
        ->whereNotNull('raw_value')
        ->count();

    $sourceCount = Indicator::where('is_active', true)
        ->whereHas('values', fn($q) => $q->where('deso_code', $deso->deso_code)->whereNotNull('raw_value'))
        ->distinct('source')
        ->count('source');

    // NEW: school count in this DeSO (number only, not names/details)
    $schoolCount = School::where('deso_code', $deso->deso_code)
        ->where('status', 'active')
        ->count();

    // NEW: nearby school count (within query radius)
    $nearbySchoolCount = DB::selectOne("
        SELECT COUNT(*) as count FROM schools
        WHERE ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, 2000)
          AND status = 'active'
    ", [$lng, $lat])->count;

    // NEW: proximity factor names (not scores)
    $proximityFactors = [
        ['slug' => 'school_proximity', 'name' => 'Närmaste skola', 'icon' => '🏫', 'available' => $nearbySchoolCount > 0],
        ['slug' => 'green_space', 'name' => 'Grönområde', 'icon' => '🌳', 'available' => true],
        ['slug' => 'transit', 'name' => 'Kollektivtrafik', 'icon' => '🚇', 'available' => true],
        ['slug' => 'grocery', 'name' => 'Livsmedel', 'icon' => '🛒', 'available' => true],
        ['slug' => 'positive_poi', 'name' => 'Service & nöje', 'icon' => '🏃', 'available' => true],
        ['slug' => 'negative_poi', 'name' => 'Negativa faktorer', 'icon' => '⚠️', 'available' => true],
    ];

    return response()->json([
        // Existing (free)
        'score' => $score->score,
        'trend_1y' => $score->trend_1y,
        'label' => $this->scoreLabel($score->score),
        'urbanity_tier' => $deso->urbanity_tier,
        'area_score' => $score->area_score ?? $score->score,
        'proximity_score' => $score->proximity_score ?? null,

        // NEW (preview metadata — no actual values)
        'preview' => [
            'data_point_count' => $dataPointCount,
            'source_count' => $sourceCount,
            'indicator_categories' => $indicators->groupBy('category')->map->count(),
            'indicators' => $indicators,
            'school_count' => $schoolCount,
            'nearby_school_count' => $nearbySchoolCount,
            'proximity_factors' => $proximityFactors,
        ],
    ]);
}
```

### 1.2 What's Free vs Locked

| Data | Free | Locked |
|---|---|---|
| Composite score (number) | ✅ | |
| Score label ("Utmanande område") | ✅ | |
| Trend arrow + direction | ✅ | |
| Area score vs proximity score | ✅ | |
| Urbanity tier | ✅ | |
| Indicator NAMES (what we measure) | ✅ | |
| Data point count + source count | ✅ | |
| Number of schools nearby | ✅ | |
| Indicator VALUES (actual numbers) | | 🔒 |
| Indicator percentile bars (filled) | | 🔒 |
| School names + meritvärden | | 🔒 |
| Proximity scores + distances | | 🔒 |
| Strengths & weaknesses | | 🔒 |

The free tier tells you **what** we know. The paid tier tells you **the answers**.

---

## Step 2: Sidebar Layout — Top to Bottom

### 2.1 Full Layout

```
┌─────────────────────────────────────────────┐
│  📍 Gömmarbäcken, Kungens kurva             │ ← address
│  Huddinge kommun · Stockholms län           │ ← location
│                                             │
│  ┌─────────────────────────────────────┐    │
│  │  38     Utmanande område            │    │ ← score card (FREE)
│  │  ↗+10.3  Semi-Urban                 │    │
│  │  Område: 43.6  Plats: 26           │    │
│  └─────────────────────────────────────┘    │
│                                             │
│  14 datapunkter · 4 källor                  │ ← data summary (FREE)
│  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━       │
│                                             │
│  ── Områdesindikatorer ─────────────        │
│                                             │
│  Medianinkomst            ░░░░░░░░░░  🔒   │ ← locked bar
│  Sysselsättningsgrad      ░░░░░░░░░░  🔒   │
│  Eftergymnasial utbildning░░░░░░░░░░  🔒   │
│  Låg ekonomisk standard   ░░░░░░░░░░  🔒   │
│  Skolkvalitet (medel)     ░░░░░░░░░░  🔒   │
│  Måluppfyllelse (medel)   ░░░░░░░░░░  🔒   │
│                                             │
│  ── Närhetsanalys ──────────────────        │
│                                             │
│  🏫 Närmaste skola        ░░░░░░░░░░  🔒   │
│  🌳 Grönområde            ░░░░░░░░░░  🔒   │
│  🚇 Kollektivtrafik       ░░░░░░░░░░  🔒   │
│  🛒 Livsmedel             ░░░░░░░░░░  🔒   │
│  🏃 Service & nöje        ░░░░░░░░░░  🔒   │
│  ⚠️ Negativa faktorer     ░░░░░░░░░░  🔒   │
│                                             │
│  ── Skolor ──── 3 skolor inom 2 km ──       │
│                                             │
│  ┌─────────────────────────────────────┐    │
│  │  ██████████████████████████████████ │    │ ← blurred/redacted
│  │  ████████ · ████████               │    │   school card
│  │  Meritvärde  ░░░░░░░░░░  🔒       │    │
│  └─────────────────────────────────────┘    │
│  ┌─────────────────────────────────────┐    │
│  │  ██████████████████████████████████ │    │
│  │  ████████ · ████████               │    │
│  │  Meritvärde  ░░░░░░░░░░  🔒       │    │
│  └─────────────────────────────────────┘    │
│  ┌─────────────────────────────────────┐    │
│  │  ██████████████████████████████████ │    │
│  │  ████████ · ████████               │    │
│  │  Meritvärde  ░░░░░░░░░░  🔒       │    │
│  └─────────────────────────────────────┘    │
│                                             │
│  ── Styrkor & svagheter ────────────        │
│                                             │
│  ✅ ████████████████████                    │ ← redacted badges
│  ✅ ██████████████                          │
│  ⚠️ ██████████████████████████              │
│  ⚠️ ████████████████                        │
│                                             │
│  ═══════════════════════════════════════    │
│                                             │
│  ┌─────────────────────────────────────┐    │
│  │                                     │    │
│  │    Lås upp fullständig rapport      │    │ ← CTA
│  │            — 79 kr                  │    │
│  │                                     │    │
│  └─────────────────────────────────────┘    │
│                                             │
│  Engångsköp · Ingen prenumeration           │
│  Inkluderar alla värden, skolnamn,          │
│  avstånd och personlig analys.              │
│                                             │
└─────────────────────────────────────────────┘
```

---

## Step 3: Component Design

### 3.1 Data Summary Bar

The "14 datapunkter · 4 källor" line. This communicates depth — we evaluated a LOT for this location.

```tsx
function DataSummary({ dataPointCount, sourceCount }: {
    dataPointCount: number;
    sourceCount: number;
}) {
    return (
        <div className="flex items-center gap-2 text-sm text-muted-foreground py-2">
            <Database className="h-3.5 w-3.5" />
            <span>
                <strong className="text-foreground">{dataPointCount}</strong> datapunkter
                {' · '}
                <strong className="text-foreground">{sourceCount}</strong> källor
            </span>
        </div>
    );
}
```

### 3.2 Locked Indicator Bar

The indicator name is readable. The bar is gray and empty. A lock icon replaces the value.

```tsx
function LockedIndicatorBar({ name, category }: {
    name: string;
    category: string;
}) {
    return (
        <div className="flex items-center gap-2 py-1.5">
            <span className="text-sm flex-1 truncate">{name}</span>
            <div className="w-24 h-2 bg-muted rounded-full" />
            <Lock className="h-3 w-3 text-muted-foreground/50" />
        </div>
    );
}
```

Compared to the unlocked version (in the full report):

```tsx
function IndicatorBar({ name, percentile, rawValue, unit }: { ... }) {
    return (
        <div className="flex items-center gap-2 py-1.5">
            <span className="text-sm flex-1 truncate">{name}</span>
            <div className="w-24 h-2 bg-muted rounded-full overflow-hidden">
                <div
                    className="h-full rounded-full bg-primary"
                    style={{ width: `${percentile * 100}%` }}
                />
            </div>
            <span className="text-xs text-muted-foreground w-16 text-right">
                {formatValue(rawValue, unit)}
            </span>
        </div>
    );
}
```

The locked version has the same dimensions and layout — just the bar is empty and the value is a lock icon. This creates a strong visual "gap" that the unlock button fills.

### 3.3 Locked School Card

School cards show redacted content. The structure is visible but text is replaced with gray blocks.

```tsx
function LockedSchoolCard() {
    return (
        <div className="border rounded-lg p-3 opacity-60">
            <div className="flex items-center gap-2 mb-2">
                <GraduationCap className="h-4 w-4 text-muted-foreground" />
                <div className="h-4 w-32 bg-muted rounded" /> {/* School name placeholder */}
            </div>
            <div className="flex items-center gap-2 mb-2">
                <div className="h-3 w-20 bg-muted rounded" /> {/* Type badge */}
                <span className="text-muted-foreground">·</span>
                <div className="h-3 w-16 bg-muted rounded" /> {/* Operator badge */}
            </div>
            <div className="flex items-center gap-2">
                <span className="text-xs text-muted-foreground">Meritvärde</span>
                <div className="w-16 h-2 bg-muted rounded-full" />
                <Lock className="h-3 w-3 text-muted-foreground/50" />
            </div>
        </div>
    );
}
```

### 3.4 Locked Strengths & Weaknesses

```tsx
function LockedFactors() {
    // Randomize widths to look like real redacted text
    const positiveWidths = [140, 100, 120];
    const negativeWidths = [160, 110];

    return (
        <div className="space-y-2">
            {positiveWidths.map((w, i) => (
                <div key={`pos-${i}`} className="flex items-center gap-2">
                    <span className="text-green-600 text-sm">✅</span>
                    <div className="h-3.5 bg-green-100 rounded" style={{ width: w }} />
                </div>
            ))}
            {negativeWidths.map((w, i) => (
                <div key={`neg-${i}`} className="flex items-center gap-2">
                    <span className="text-amber-600 text-sm">⚠️</span>
                    <div className="h-3.5 bg-amber-100 rounded" style={{ width: w }} />
                </div>
            ))}
        </div>
    );
}
```

The redacted blocks have varying widths so they look like real text that's been covered, not a uniform pattern. The green/amber colors hint at the content without revealing it.

### 3.5 Section Headers

```tsx
function SectionHeader({ title, subtitle }: { title: string; subtitle?: string }) {
    return (
        <div className="flex items-center justify-between pt-4 pb-2 border-t mt-4">
            <h3 className="text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                {title}
            </h3>
            {subtitle && (
                <span className="text-xs text-muted-foreground">{subtitle}</span>
            )}
        </div>
    );
}
```

---

## Step 4: The CTA — Redesign

### 4.1 CTA Position

The CTA button should appear **after all the locked content**, not before it. The user scrolls through locked indicators, locked schools, locked factors — building tension — and THEN hits the unlock button.

But also: a **sticky CTA at the bottom** of the sidebar for users who don't scroll. Both placements, not just one.

### 4.2 Inline CTA (After All Locked Content)

```tsx
function InlineUnlockCTA({ lat, lng }: { lat: number; lng: number }) {
    return (
        <div className="mt-6 pt-6 border-t">
            <div className="bg-gradient-to-br from-primary/5 to-primary/10 rounded-lg p-5 text-center">
                <Lock className="h-6 w-6 text-primary mx-auto mb-3" />
                <h3 className="font-semibold mb-1">Se alla värden</h3>
                <p className="text-sm text-muted-foreground mb-4">
                    Alla indikatorer, skolnamn, avstånd
                    och personlig analys.
                </p>
                <a href={`/purchase/${lat},${lng}`}>
                    <Button className="w-full" size="lg">
                        Lås upp — 79 kr
                    </Button>
                </a>
                <p className="text-xs text-muted-foreground mt-2">
                    Engångsköp · Ingen prenumeration
                </p>
            </div>
        </div>
    );
}
```

### 4.3 Sticky Bottom CTA

When the user scrolls past the first CTA (the one currently shown after the score), show a slim sticky bar at the bottom of the sidebar:

```tsx
function StickyUnlockBar({ lat, lng, visible }: { lat: number; lng: number; visible: boolean }) {
    if (!visible) return null;

    return (
        <div className="sticky bottom-0 bg-background/95 backdrop-blur border-t p-3">
            <a href={`/purchase/${lat},${lng}`} className="block">
                <Button className="w-full" size="sm">
                    Lås upp fullständig rapport — 79 kr
                </Button>
            </a>
        </div>
    );
}
```

The sticky bar appears when the user scrolls down into the locked content area. It disappears when they scroll back up to the original CTA. Use `IntersectionObserver` on the first CTA element to toggle visibility.

```tsx
const [showStickyBar, setShowStickyBar] = useState(false);
const firstCtaRef = useRef<HTMLDivElement>(null);

useEffect(() => {
    if (!firstCtaRef.current) return;

    const observer = new IntersectionObserver(
        ([entry]) => setShowStickyBar(!entry.isIntersecting),
        { threshold: 0 }
    );

    observer.observe(firstCtaRef.current);
    return () => observer.disconnect();
}, []);
```

---

## Step 5: Assembled Sidebar

```tsx
function SidebarContent({ location, preview, lat, lng }: Props) {
    const [showStickyBar, setShowStickyBar] = useState(false);
    const firstCtaRef = useRef<HTMLDivElement>(null);

    // IntersectionObserver for sticky CTA
    useEffect(() => { /* ... as above ... */ }, []);

    return (
        <ScrollArea className="h-full">
            <div className="p-4 space-y-0">
                {/* Header — FREE */}
                <LocationHeader address={location.address} kommun={location.kommun_name} lan={location.lan_name} />

                {/* Score card — FREE */}
                <ScoreCard
                    score={location.score}
                    trend={location.trend_1y}
                    label={location.label}
                    urbanityTier={location.urbanity_tier}
                    areaScore={location.area_score}
                    proximityScore={location.proximity_score}
                />

                {/* Data summary — FREE */}
                <DataSummary
                    dataPointCount={preview.data_point_count}
                    sourceCount={preview.source_count}
                />

                {/* First CTA position (original) */}
                <div ref={firstCtaRef}>
                    <a href={`/purchase/${lat},${lng}`}>
                        <Button className="w-full" size="lg">
                            Lås upp fullständig rapport — 79 kr
                        </Button>
                    </a>
                    <p className="text-xs text-muted-foreground text-center mt-1.5">
                        Engångsköp · Ingen prenumeration
                    </p>
                </div>

                {/* Area indicators — LOCKED */}
                <SectionHeader title="Områdesindikatorer" />
                {preview.indicators
                    .filter((i: any) => i.category !== 'proximity')
                    .map((indicator: any) => (
                        <LockedIndicatorBar
                            key={indicator.slug}
                            name={indicator.name}
                            category={indicator.category}
                        />
                    ))
                }

                {/* Proximity — LOCKED */}
                <SectionHeader title="Närhetsanalys" />
                {preview.proximity_factors.map((factor: any) => (
                    <LockedIndicatorBar
                        key={factor.slug}
                        name={`${factor.icon} ${factor.name}`}
                        category="proximity"
                    />
                ))}

                {/* Schools — LOCKED */}
                <SectionHeader
                    title="Skolor"
                    subtitle={preview.nearby_school_count > 0
                        ? `${preview.nearby_school_count} skolor inom 2 km`
                        : 'Inga skolor i närheten'
                    }
                />
                {preview.nearby_school_count > 0 ? (
                    <div className="space-y-2">
                        {Array.from({ length: Math.min(preview.nearby_school_count, 3) }).map((_, i) => (
                            <LockedSchoolCard key={i} />
                        ))}
                        {preview.nearby_school_count > 3 && (
                            <p className="text-xs text-muted-foreground text-center">
                                + {preview.nearby_school_count - 3} fler skolor
                            </p>
                        )}
                    </div>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        Rapporten visar närmaste skola med avstånd.
                    </p>
                )}

                {/* Strengths & weaknesses — LOCKED */}
                <SectionHeader title="Styrkor & svagheter" />
                <LockedFactors />

                {/* Final CTA — after all locked content */}
                <InlineUnlockCTA lat={lat} lng={lng} />
            </div>

            {/* Sticky bottom CTA */}
            <StickyUnlockBar lat={lat} lng={lng} visible={showStickyBar} />
        </ScrollArea>
    );
}
```

---

## Step 6: Visual Polish

### 6.1 Locked State Styling

The locked sections should feel "there but inaccessible" — not broken or missing. Use these patterns:

- **Opacity:** Locked sections at `opacity-60`, not `opacity-30` (too faded looks broken)
- **Gray bars:** `bg-muted` (Tailwind) for all placeholder bars. Not `bg-gray-200` — use the theme token so it works in dark mode
- **Lock icons:** Small (h-3 w-3), muted color (`text-muted-foreground/50`), positioned where the value would be
- **No blur filter on text:** Blurred text looks hacky and CSS-blur is hard to get right. Use solid gray blocks instead. They read as "redacted" which is more intentional.
- **Redacted text blocks:** Rounded corners (`rounded`), varying widths, category-appropriate colors (green-100 for strengths, amber-100 for weaknesses, muted for general)

### 6.2 Animation

When the sidebar first loads with locked content, add a subtle stagger animation. Each locked row fades in with a slight delay (30ms per row). This draws the eye down through the locked content, showing the user how much is there.

```tsx
// In LockedIndicatorBar
<div
    className="flex items-center gap-2 py-1.5 animate-in fade-in"
    style={{ animationDelay: `${index * 30}ms` }}
>
```

### 6.3 Hover State on Locked Items

When the user hovers over a locked indicator row, show a subtle tooltip or cursor change:

```tsx
<div className="... cursor-pointer group" onClick={() => scrollToCta()}>
    {/* On hover, the lock icon gets slightly more visible */}
    <Lock className="h-3 w-3 text-muted-foreground/50 group-hover:text-muted-foreground transition-colors" />
</div>
```

Clicking any locked row scrolls to the CTA. The locked content is itself a call to action.

---

## Step 7: Source Attribution

### 7.1 Source Badges

Below the data summary, optionally show which sources were used. This builds credibility:

```tsx
function SourceBadges({ sources }: { sources: string[] }) {
    const sourceLabels: Record<string, string> = {
        scb: 'SCB',
        skolverket: 'Skolverket',
        gtfs: 'Trafiklab',
        osm: 'OpenStreetMap',
        bra: 'BRÅ',
        kronofogden: 'Kronofogden',
    };

    return (
        <div className="flex flex-wrap gap-1.5 mb-3">
            {sources.map(source => (
                <span
                    key={source}
                    className="text-[10px] px-1.5 py-0.5 bg-muted rounded text-muted-foreground"
                >
                    {sourceLabels[source] ?? source}
                </span>
            ))}
        </div>
    );
}
```

Shows: `SCB  Skolverket  Trafiklab  OpenStreetMap` — small badges that say "this is real government data, not vibes."

### 7.2 API Response Addition

Add source list to the preview response:

```php
'sources' => Indicator::where('is_active', true)
    ->whereHas('values', fn($q) => $q->where('deso_code', $deso->deso_code)->whereNotNull('raw_value'))
    ->distinct()
    ->pluck('source')
    ->values(),
```

---

## Verification

### Visual
- [ ] Score card renders as before (no change to free content)
- [ ] "14 datapunkter · 4 källor" shows real counts from database
- [ ] Source badges show actual data sources used for this location
- [ ] Area indicator section shows all active indicator NAMES with locked bars
- [ ] Proximity section shows 6 factor names with locked bars
- [ ] School section shows correct count ("3 skolor inom 2 km") with redacted cards
- [ ] Strengths/weaknesses shows redacted blocks with green/amber colors
- [ ] CTA appears both after score (original position) and after all locked content
- [ ] Sticky CTA appears when scrolling past the first CTA
- [ ] Sticky CTA disappears when scrolling back up

### Interaction
- [ ] Clicking any locked row scrolls to the CTA
- [ ] Hovering locked rows shows subtle lock icon highlight
- [ ] CTA links to `/purchase/{lat},{lng}` correctly
- [ ] All locked content renders even when there's no data (show "0 skolor" not a crash)

### Edge Cases
- [ ] DeSO with no schools → school section shows "Inga skolor i närheten" + "Rapporten visar närmaste skola"
- [ ] DeSO with no indicator data → still shows indicator names with locked bars (structure visible, "0 datapunkter" in summary)
- [ ] Very long sidebar content scrolls independently of map
- [ ] Mobile: locked content still legible, sticky CTA has enough touch target

### Conversion Signal
- [ ] The locked sidebar creates a clear "what you'd get" narrative
- [ ] User can count: "there are 6 area indicators, 6 proximity factors, 3 schools, and 5 strengths/weaknesses I can't see"
- [ ] The gap between "I can see the score" and "I can't see why" creates natural tension toward the CTA

---

## What NOT to Do

- **DO NOT use CSS blur on text.** Gray blocks look intentional. Blur looks like a rendering bug.
- **DO NOT show fake/dummy data.** Never show "Meritvärde: 234" as placeholder. Show structure with real metadata (names, counts) and hide values. Users who see fake data lose trust.
- **DO NOT hide the locked sections entirely.** The whole point is showing what's behind the paywall. An empty sidebar doesn't sell anything.
- **DO NOT make locked indicators clickable to individual purchases.** One product: the full report. One CTA. No per-indicator microtransactions.
- **DO NOT over-animate.** Subtle fade-in stagger is enough. No bouncing lock icons, no pulsing CTAs, no confetti. Professional, not pushy.
- **DO NOT show more than 3 locked school cards.** Show 3 + "X fler skolor" text. Too many cards makes the sidebar feel padded.
- **DO NOT change the free tier data.** The score, trend, label, urbanity tier, area/proximity breakdown all stay free. This task adds locked content BELOW, it doesn't take away anything that's currently free.