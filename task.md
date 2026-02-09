# TASK: Vulnerability Area Penalty System + Map Layer

## Context

The `vulnerability_flag` is currently stored as a regular indicator with percentile normalization. This is wrong. A binary flag (0 or 1) forced into a percentile ranking means 95% of DeSOs get percentile 0 and the 5% in vulnerability areas get percentile 100 — there's no gradient, no nuance. It wastes an indicator slot and an indicator weight budget on what is fundamentally a **pass/fail classification**.

The correct model: vulnerability areas apply a **hard penalty** to the composite score. If your DeSO overlaps a "särskilt utsatt område," you lose X points off your final score, period. No percentile, no weighting against other indicators. It's a separate mechanism — a penalty applied *after* the weighted average, not *inside* it.

Additionally, vulnerability area polygons should be visible on the map as their own layer — distinct boundaries that users can see independently from the DeSO coloring. Everyone in Sweden knows "the list." Making it visible adds credibility and drives engagement.

---

## Step 1: Remove `vulnerability_flag` as an Indicator

### 1.1 Deactivate the Indicator

Don't delete it — deactivate it and set weight to 0:

```php
// Migration or seeder update
Indicator::where('slug', 'vulnerability_flag')->update([
    'is_active' => false,
    'weight' => 0,
    'direction' => 'neutral',
    'description' => 'DEPRECATED — replaced by hard penalty system. See vulnerability_penalties config.',
]);
```

### 1.2 Clean Up Indicator Values

The `indicator_values` rows for `vulnerability_flag` can stay — they don't hurt anything and provide historical reference. But they no longer feed into scoring.

### 1.3 Redistribute Weight

The `vulnerability_flag` had weight ~0.095 (from the indicator architecture cleanup). This weight goes back to the unallocated budget or gets redistributed to other safety indicators:

| Indicator | Old Weight | New Weight | Change |
|---|---|---|---|
| vulnerability_flag | 0.095 | 0.000 | Removed |
| crime_violent_rate | 0.060 | 0.080 | +0.020 |
| crime_property_rate | 0.045 | 0.055 | +0.010 |
| perceived_safety | 0.045 | 0.060 | +0.015 |
| Penalty system | n/a | n/a | Separate mechanism, up to -15 pts |

The total scored weight for safety drops from 0.25 to ~0.195 in the weighted average, but the penalty system more than compensates — a -15 point penalty is far more impactful than a 0.095 weight on a binary flag.

---

## Step 2: Penalty Configuration

### 2.1 Config Table

Create a new table for penalty rules. Start with vulnerability areas, but the structure supports future penalties (e.g., Seveso sites, flood zones, noise zones).

```php
Schema::create('score_penalties', function (Blueprint $table) {
    $table->id();
    $table->string('slug', 60)->unique()->index();     // 'vuln_sarskilt_utsatt', 'vuln_utsatt'
    $table->string('name');                              // 'Särskilt utsatt område'
    $table->string('description')->nullable();
    $table->string('category', 40);                     // 'vulnerability', 'environment', etc.
    $table->string('penalty_type', 20);                  // 'absolute' or 'percentage'
    $table->decimal('penalty_value', 6, 2);              // -15.00 (absolute points) or -0.15 (15% reduction)
    $table->boolean('is_active')->default(true);
    $table->string('applies_to', 40)->default('composite_score'); // What score it modifies
    $table->integer('display_order')->default(0);
    $table->string('color', 7)->nullable();              // Map layer color: '#dc2626'
    $table->string('border_color', 7)->nullable();       // Map layer border: '#991b1b'
    $table->decimal('opacity', 3, 2)->default(0.15);     // Map layer fill opacity
    $table->json('metadata')->nullable();
    $table->timestamps();
});
```

### 2.2 Seed Default Penalties

```php
// database/seeders/ScorePenaltySeeder.php

ScorePenalty::upsert([
    [
        'slug' => 'vuln_sarskilt_utsatt',
        'name' => 'Särskilt utsatt område',
        'description' => 'Område med parallella samhällsstrukturer, systematisk ovilja att medverka i rättsprocessen, och extremism som påverkar lokalsamhället. Klassificerat av Polismyndigheten.',
        'category' => 'vulnerability',
        'penalty_type' => 'absolute',
        'penalty_value' => -15.00,        // -15 points off composite score
        'is_active' => true,
        'display_order' => 1,
        'color' => '#dc2626',             // Red
        'border_color' => '#991b1b',
        'opacity' => 0.20,
    ],
    [
        'slug' => 'vuln_utsatt',
        'name' => 'Utsatt område',
        'description' => 'Område med låg socioekonomisk status, kriminell påverkan på lokalsamhället, och invånare som upplever otrygghet. Klassificerat av Polismyndigheten.',
        'category' => 'vulnerability',
        'penalty_type' => 'absolute',
        'penalty_value' => -8.00,         // -8 points off composite score
        'is_active' => true,
        'display_order' => 2,
        'color' => '#f97316',             // Orange
        'border_color' => '#c2410c',
        'opacity' => 0.15,
    ],
], ['slug']);
```

### 2.3 Why Absolute Points, Not Percentage

**Absolute** (`-15 points`): A DeSO scoring 50 drops to 35. A DeSO scoring 30 drops to 15. The penalty has the same absolute impact everywhere. This makes sense because the vulnerability classification is absolute — Polisen doesn't say "relatively vulnerable," they say "this area has parallel societal structures and systematic witness intimidation."

**Percentage** (`-15%`): A DeSO scoring 50 drops to 42.5. A DeSO scoring 30 drops to 25.5. Weaker areas get smaller penalties, which is backwards — being in a vulnerability zone is *worse* if you're already scoring low.

**Use absolute.** The admin can switch to percentage later if needed — the `penalty_type` column supports both.

---

## Step 3: Integrate Penalties into Scoring Engine

### 3.1 Modify `ScoringService::computeScores()`

After computing the weighted average composite score, apply penalties:

```php
// In ScoringService.php

public function computeScores(int $year): void
{
    $indicators = Indicator::where('is_active', true)->where('weight', '>', 0)->get();
    $penalties = ScorePenalty::where('is_active', true)->get();

    // Pre-load DeSO → vulnerability area mappings
    $desoVulnerabilities = $this->loadDesoVulnerabilityMappings();

    foreach ($allDesoCodes as $desoCode) {
        // 1. Compute weighted average (existing logic)
        $rawScore = $this->computeWeightedAverage($desoCode, $indicators, $year);

        // 2. Apply penalties
        $appliedPenalties = [];
        $totalPenalty = 0;

        foreach ($penalties as $penalty) {
            if ($this->penaltyApplies($desoCode, $penalty, $desoVulnerabilities)) {
                $penaltyAmount = match($penalty->penalty_type) {
                    'absolute' => $penalty->penalty_value,                    // e.g., -15
                    'percentage' => $rawScore * ($penalty->penalty_value / 100), // e.g., -15% of score
                };

                $totalPenalty += $penaltyAmount;
                $appliedPenalties[] = [
                    'slug' => $penalty->slug,
                    'name' => $penalty->name,
                    'amount' => round($penaltyAmount, 2),
                ];
            }
        }

        // 3. Final score = raw score + penalties, clamped to 0-100
        $finalScore = max(0, min(100, $rawScore + $totalPenalty));

        // 4. Store
        CompositeScore::updateOrCreate(
            ['deso_code' => $desoCode, 'year' => $year],
            [
                'score' => round($finalScore, 2),
                'raw_score_before_penalties' => round($rawScore, 2),  // NEW column
                'penalties_applied' => $appliedPenalties ?: null,      // NEW column (json)
                'trend_1y' => ...,
                'factor_scores' => ...,
                'top_positive' => ...,
                'top_negative' => ...,
                'computed_at' => now(),
            ]
        );
    }
}

private function penaltyApplies(string $desoCode, ScorePenalty $penalty, Collection $mappings): bool
{
    if ($penalty->category !== 'vulnerability') {
        return false; // Future: other penalty types with their own logic
    }

    $desoMappings = $mappings->get($desoCode, collect());

    return match($penalty->slug) {
        'vuln_sarskilt_utsatt' => $desoMappings->contains(fn($m) => $m->tier === 'sarskilt_utsatt'),
        'vuln_utsatt' => $desoMappings->contains(fn($m) => $m->tier === 'utsatt'),
        default => false,
    };
}

private function loadDesoVulnerabilityMappings(): Collection
{
    return DB::table('deso_vulnerability_mapping')
        ->where('overlap_fraction', '>=', 0.10) // At least 10% overlap
        ->get()
        ->groupBy('deso_code');
}
```

### 3.2 New Columns on `composite_scores`

```php
Schema::table('composite_scores', function (Blueprint $table) {
    $table->decimal('raw_score_before_penalties', 6, 2)->nullable()->after('score');
    $table->json('penalties_applied')->nullable()->after('top_negative');
});
```

### 3.3 Penalty Stacking

A DeSO can technically be flagged as both "utsatt" and "särskilt utsatt" if it overlaps multiple vulnerability area polygons of different tiers. **Apply only the worst penalty per category**, not both:

```php
// Group penalties by category, apply only the strongest per category
$penaltiesByCategory = collect($applicablePenalties)->groupBy('category');
$effectivePenalties = $penaltiesByCategory->map(function ($group) {
    return $group->sortBy('penalty_value')->first(); // Most negative value = worst penalty
});
```

So a DeSO overlapping both an "utsatt" (-8) and a "särskilt utsatt" (-15) area gets -15, not -23.

### 3.4 Overlap Threshold

The `deso_vulnerability_mapping` table has an `overlap_fraction` column. A DeSO with only 2% overlap (a sliver touching the edge of a vulnerability area) shouldn't get the full penalty. Options:

**Simple (recommended for v1):** Binary — if overlap >= 10%, apply full penalty. Below 10%, no penalty.

**Future refinement:** Scale penalty by overlap fraction: `effective_penalty = base_penalty × min(1.0, overlap_fraction × 2)`. So 50%+ overlap = full penalty, 25% overlap = half penalty. But this adds complexity and the admin can't easily reason about it. Keep it simple.

---

## Step 4: Admin Panel for Penalties

### 4.1 Route

```php
Route::prefix('admin')->group(function () {
    Route::get('/penalties', [AdminPenaltyController::class, 'index'])->name('admin.penalties');
    Route::put('/penalties/{penalty}', [AdminPenaltyController::class, 'update'])->name('admin.penalties.update');
});
```

### 4.2 Controller

```php
class AdminPenaltyController extends Controller
{
    public function index()
    {
        $penalties = ScorePenalty::orderBy('category')->orderBy('display_order')->get();

        // How many DeSOs are affected by each penalty?
        $affectedCounts = DB::table('deso_vulnerability_mapping')
            ->where('overlap_fraction', '>=', 0.10)
            ->select('tier', DB::raw('COUNT(DISTINCT deso_code) as deso_count'))
            ->groupBy('tier')
            ->pluck('deso_count', 'tier');

        // Population affected
        $affectedPopulation = DB::table('deso_vulnerability_mapping as dvm')
            ->join('deso_areas as da', 'da.deso_code', '=', 'dvm.deso_code')
            ->where('dvm.overlap_fraction', '>=', 0.10)
            ->select('dvm.tier', DB::raw('SUM(da.population) as pop'))
            ->groupBy('dvm.tier')
            ->pluck('pop', 'tier');

        return Inertia::render('Admin/Penalties', [
            'penalties' => $penalties->map(fn($p) => [
                ...$p->toArray(),
                'affected_desos' => $affectedCounts[$this->tierFromSlug($p->slug)] ?? 0,
                'affected_population' => $affectedPopulation[$this->tierFromSlug($p->slug)] ?? 0,
            ]),
        ]);
    }

    public function update(Request $request, ScorePenalty $penalty)
    {
        $validated = $request->validate([
            'penalty_value' => 'required|numeric|min:-50|max:0',
            'penalty_type' => 'required|in:absolute,percentage',
            'is_active' => 'required|boolean',
            'color' => 'nullable|string|max:7',
            'border_color' => 'nullable|string|max:7',
            'opacity' => 'nullable|numeric|min:0|max:1',
        ]);

        $penalty->update($validated);

        return back()->with('success', 'Penalty updated. Recompute scores to apply changes.');
    }
}
```

### 4.3 Admin Page UI

```
┌─────────────────────────────────────────────────────────────────┐
│  Poängavdrag & straffsystem                                     │
│                                                                  │
│  Dessa avdrag appliceras EFTER den viktade poängberäkningen.     │
│  De påverkar den slutliga kompositspoängen direkt.               │
│                                                                  │
│  ┌───────────────────────────────────────────────────────────┐  │
│  │ Polisens utsatta områden                                  │  │
│  │                                                            │  │
│  │ ┌──────────────────────────────────────────────────────┐  │  │
│  │ │  ● Särskilt utsatt område                            │  │  │
│  │ │                                                       │  │  │
│  │ │  Avdrag:  [____-15____] poäng     Typ: ○ Absolut     │  │  │
│  │ │                                        ○ Procent     │  │  │
│  │ │  Aktiv:   [✓]                                        │  │  │
│  │ │                                                       │  │  │
│  │ │  Påverkar: 87 DeSO-områden · ~180 000 invånare       │  │  │
│  │ │                                                       │  │  │
│  │ │  Kartfärg: [🔴 #dc2626]  Kant: [🔴 #991b1b]         │  │  │
│  │ │  Opacitet: [____0.20____]                             │  │  │
│  │ │                                                       │  │  │
│  │ │  Simulering: En DeSO med poäng 50 → 35 efter avdrag  │  │  │
│  │ │              En DeSO med poäng 30 → 15 efter avdrag  │  │  │
│  │ │              En DeSO med poäng 10 → 0 (golv)         │  │  │
│  │ └──────────────────────────────────────────────────────┘  │  │
│  │                                                            │  │
│  │ ┌──────────────────────────────────────────────────────┐  │  │
│  │ │  ● Utsatt område                                     │  │  │
│  │ │                                                       │  │  │
│  │ │  Avdrag:  [_____-8____] poäng     Typ: ○ Absolut     │  │  │
│  │ │  Aktiv:   [✓]                                        │  │  │
│  │ │                                                       │  │  │
│  │ │  Påverkar: 142 DeSO-områden · ~370 000 invånare      │  │  │
│  │ │                                                       │  │  │
│  │ │  Kartfärg: [🟠 #f97316]  Kant: [🟠 #c2410c]         │  │  │
│  │ │  Opacitet: [____0.15____]                             │  │  │
│  │ │                                                       │  │  │
│  │ │  Simulering: En DeSO med poäng 50 → 42 efter avdrag  │  │  │
│  │ └──────────────────────────────────────────────────────┘  │  │
│  └───────────────────────────────────────────────────────────┘  │
│                                                                  │
│  OBS: Ändring av avdrag kräver omberäkning av poäng.            │
│  [   Spara ändringar   ]   [   Beräkna om poäng   ]            │
│                                                                  │
│  Källa: Polismyndigheten, "Lägesbild utsatta områden 2025"      │
│  Senast uppdaterad: Dec 2025 · Nästa uppdatering: ~2027          │
└─────────────────────────────────────────────────────────────────┘
```

Key admin features:
- **Penalty value input** — adjust the point deduction (default -15 / -8)
- **Type toggle** — switch between absolute points and percentage
- **Active toggle** — disable a penalty without deleting it
- **Impact stats** — how many DeSOs and people are affected
- **Simulation preview** — shows what happens to a DeSO at score 50, 30, 10
- **Map styling controls** — color, border, opacity for the map layer
- **Recompute button** — triggers score recomputation after changes

---

## Step 5: Map Layer — Vulnerability Area Polygons

### 5.1 API Endpoint

```php
Route::get('/api/vulnerability-areas', [VulnerabilityAreaController::class, 'index']);
```

```php
public function index()
{
    $penalties = ScorePenalty::where('category', 'vulnerability')
        ->where('is_active', true)
        ->get()
        ->keyBy('slug');

    $areas = DB::table('vulnerability_areas')
        ->where('is_current', true)
        ->select(
            'id', 'name', 'tier', 'police_region', 'municipality_name',
            DB::raw("ST_AsGeoJSON(ST_SimplifyPreserveTopology(geom, 0.0001)) as geojson")
        )
        ->get()
        ->map(function ($area) use ($penalties) {
            $penaltySlug = 'vuln_' . $area->tier;
            $penalty = $penalties->get($penaltySlug);

            return [
                'id' => $area->id,
                'name' => $area->name,
                'tier' => $area->tier,
                'tier_label' => match($area->tier) {
                    'sarskilt_utsatt' => 'Särskilt utsatt område',
                    'utsatt' => 'Utsatt område',
                    default => $area->tier,
                },
                'police_region' => $area->police_region,
                'municipality' => $area->municipality_name,
                'penalty_points' => $penalty?->penalty_value,
                'color' => $penalty?->color ?? '#ef4444',
                'border_color' => $penalty?->border_color ?? '#991b1b',
                'opacity' => $penalty?->opacity ?? 0.15,
                'geojson' => json_decode($area->geojson),
            ];
        });

    return response()->json($areas)
        ->header('Cache-Control', 'public, max-age=86400'); // 24h cache — rarely changes
}
```

### 5.2 Frontend Map Layer

Add vulnerability areas as a separate OpenLayers vector layer, rendered ABOVE the DeSO heatmap/coloring but BELOW school markers and pins.

```tsx
// In the map component

function useVulnerabilityLayer(map: OlMap | null) {
    const [areas, setAreas] = useState<VulnerabilityArea[]>([]);

    useEffect(() => {
        fetch('/api/vulnerability-areas')
            .then(r => r.json())
            .then(setAreas);
    }, []);

    useEffect(() => {
        if (!map || !areas.length) return;

        const features = areas.map(area => {
            const feature = new GeoJSON().readFeature(
                { type: 'Feature', geometry: area.geojson, properties: area },
                { featureProjection: 'EPSG:3857' }
            );
            return feature;
        });

        const source = new VectorSource({ features });

        const layer = new VectorLayer({
            source,
            style: (feature) => {
                const props = feature.getProperties();
                return new Style({
                    fill: new Fill({
                        color: hexToRgba(props.color, props.opacity),
                    }),
                    stroke: new Stroke({
                        color: props.border_color,
                        width: 2,
                        lineDash: [6, 4],  // Dashed border to distinguish from DeSO borders
                    }),
                });
            },
            zIndex: 15,  // Above DeSO coloring (10), below school markers (20) and pins (25)
        });

        layer.set('name', 'vulnerability-areas');
        map.addLayer(layer);

        return () => map.removeLayer(layer);
    }, [map, areas]);
}
```

### 5.3 Visual Styling

The vulnerability area polygons should be clearly visible but not overwhelming:

| Tier | Fill Color | Fill Opacity | Border | Border Style |
|---|---|---|---|---|
| Särskilt utsatt | Red (#dc2626) | 0.20 | Dark red (#991b1b), 2px | Dashed |
| Utsatt | Orange (#f97316) | 0.15 | Dark orange (#c2410c), 2px | Dashed |

Dashed borders are critical — they distinguish vulnerability area boundaries from DeSO boundaries (which are solid). The user can see both layers simultaneously.

### 5.4 Hover Tooltip

When the user hovers over a vulnerability area polygon, show a tooltip:

```
┌──────────────────────────────────────┐
│  ⚠️ Rinkeby                          │
│  Särskilt utsatt område              │
│  Polisregion Stockholm               │
│                                       │
│  Avdrag: -15 poäng på                │
│  kompositspoängen                     │
│  Källa: Polismyndigheten 2025         │
└──────────────────────────────────────┘
```

```tsx
// In the hover handler
map.on('pointermove', (evt) => {
    // Check vulnerability layer first (higher z-index)
    const vulnFeature = map.forEachFeatureAtPixel(evt.pixel, (f) => f, {
        layerFilter: (l) => l.get('name') === 'vulnerability-areas',
    });

    if (vulnFeature) {
        const props = vulnFeature.getProperties();
        showTooltip(evt.pixel, {
            title: `⚠️ ${props.name}`,
            subtitle: props.tier_label,
            detail: `Avdrag: ${props.penalty_points} poäng`,
            source: 'Polismyndigheten 2025',
        });
        return;
    }

    // ... existing DeSO hover logic
});
```

### 5.5 Legend Entry

Add vulnerability areas to the map legend:

```
── Utsatta områden ──
  ┊╌╌╌╌╌╌┊  Särskilt utsatt (-15 poäng)
  ┊╌╌╌╌╌╌┊  Utsatt (-8 poäng)
```

### 5.6 Toggle Visibility

Add a toggle in the map controls to show/hide vulnerability areas:

```tsx
<div className="flex items-center gap-2">
    <Switch
        checked={showVulnerabilityAreas}
        onCheckedChange={(v) => {
            setShowVulnerabilityAreas(v);
            vulnLayer?.setVisible(v);
        }}
    />
    <Label className="text-xs">Visa utsatta områden</Label>
</div>
```

Default: **visible**. The boundaries are public information from Polisen and a major product differentiator.

---

## Step 6: Sidebar — Penalty Display

### 6.1 When a DeSO Has a Penalty

If the selected DeSO falls within a vulnerability area, show a penalty notice in the sidebar between the score and the indicator breakdown:

```
┌─────────────────────────────────────┐
│  Poäng: 35                          │
│  Förhöjd risk                       │
│                                     │
│  ┌─────────────────────────────┐    │
│  │ ⚠️ Särskilt utsatt område   │    │
│  │ Rinkeby                      │    │
│  │                              │    │
│  │ Poäng före avdrag: 50        │    │
│  │ Avdrag: -15 poäng            │    │
│  │ Poäng efter avdrag: 35       │    │
│  │                              │    │
│  │ Polismyndigheten 2025        │    │
│  └─────────────────────────────┘    │
│                                     │
│  ── Indikatorer ──                  │
│  ...                                │
└─────────────────────────────────────┘
```

This transparency is critical — the user should understand *why* the score is low and that it's because of a police classification, not just bad demographics.

### 6.2 In the Report

The report (from `task-report-page.md`) should also show the penalty in the hero score section and add a dedicated section explaining the vulnerability classification.

---

## Step 7: Include Penalty in Score API

### 7.1 Extend Score Response

The `/api/deso/scores` endpoint should include penalty information:

```php
$scores = DB::table('composite_scores')
    ->where('year', $year)
    ->select('deso_code', 'score', 'raw_score_before_penalties', 'trend_1y', 'penalties_applied')
    ->get()
    ->keyBy('deso_code');
```

The frontend can then show penalty info in the sidebar without an extra API call.

---

## Verification

### Score Impact
```sql
-- DeSOs with penalties applied
SELECT cs.deso_code, da.kommun_name,
       cs.raw_score_before_penalties,
       cs.score,
       cs.penalties_applied
FROM composite_scores cs
JOIN deso_areas da ON da.deso_code = cs.deso_code
WHERE cs.penalties_applied IS NOT NULL
  AND cs.year = 2024
ORDER BY cs.score ASC
LIMIT 20;

-- The penalty should be visible: raw_score_before_penalties - score = penalty amount
-- Rinkeby: raw 42, penalty -15, final 27
-- Rosengård: raw 38, penalty -15, final 23

-- Verify no DeSO gets double-penalized
SELECT deso_code,
       jsonb_array_length(penalties_applied::jsonb) as penalty_count
FROM composite_scores
WHERE penalties_applied IS NOT NULL
  AND jsonb_array_length(penalties_applied::jsonb) > 1;
-- Should return 0 rows (only worst penalty per category applied)
```

### Map Layer
- [ ] Vulnerability area polygons visible on the map as dashed-border overlays
- [ ] Red = särskilt utsatt, orange = utsatt (matching admin-configured colors)
- [ ] Polygons are above DeSO coloring, below school markers
- [ ] Hovering shows tooltip with area name, tier, penalty amount
- [ ] Toggle in map controls can show/hide the layer
- [ ] Legend includes vulnerability area entries

### Admin Panel
- [ ] `/admin/penalties` page shows both penalty types
- [ ] Penalty value is editable (input field)
- [ ] Type toggle works (absolute/percentage)
- [ ] Active toggle works
- [ ] Impact stats show correct DeSO count and population
- [ ] Simulation preview updates when penalty value changes
- [ ] Map color/opacity controls work
- [ ] Recompute button triggers score recalculation

### Scoring
- [ ] `vulnerability_flag` indicator is inactive (weight 0, not scored)
- [ ] Composite scores for vulnerability DeSOs show `raw_score_before_penalties` > `score`
- [ ] Penalty is clamped (score never goes below 0)
- [ ] Only the worst penalty per category applies (no stacking)
- [ ] Overlap threshold (10%) is respected

### Sidebar
- [ ] DeSOs in vulnerability areas show penalty notice between score and indicators
- [ ] Shows raw score, penalty amount, and final score
- [ ] Links to Polismyndigheten source

---

## What NOT to Do

- **DO NOT delete the `vulnerability_flag` indicator or its `indicator_values`.** Deactivate it. The historical data is useful for analysis and the completeness dashboard.
- **DO NOT apply penalties during normalization.** Penalties apply AFTER the weighted average, as the last step before clamping to 0-100.
- **DO NOT stack multiple vulnerability penalties.** If a DeSO overlaps both tiers, apply only the worst one.
- **DO NOT hardcode penalty values in the scoring service.** Read from `score_penalties` table. The admin must be able to change values without code deployment.
- **DO NOT hide vulnerability areas behind a paywall.** These boundaries are public data from Polisen. Showing them builds credibility. The detailed per-DeSO penalty breakdown and score impact analysis can be in the paid report.
- **DO NOT make penalty values positive.** They are always negative (point deductions). The admin input should enforce `max: 0`.