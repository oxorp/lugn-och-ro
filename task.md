# TASK: Safety-Modulated Proximity Scoring

## Depends On

- Proximity scoring task (must be implemented first)
- POI ingestion (parks, transit, grocery, etc. must exist in `pois` table)
- Crime data ingestion (BRÅ) is ideal but not required — we can bootstrap from existing area-level indicators

## The Problem

Kronoparken in Karlstad scores higher than it should because the proximity model treats a cinema 500m away the same whether you're in Djursholm or in a gang-dominated area. A park bench surrounded by drug dealing isn't a park amenity — it's a hazard. The current model is blind to this.

The fix: **effective distance increases when the area feels unsafe.** A 500m walk through a safe neighborhood stays 500m. A 500m walk through an unsafe area becomes 1000m+ in the decay function. The amenity still exists, but the model recognizes you get less value from it.

Different amenity types are affected differently. You'll brave a sketchy walk for groceries (necessity). You won't brave it for a leisurely park visit (discretionary). The safety penalty varies by category.

---

## Step 1: Safety Score Computation

### 1.1 What We Have Now

We don't have BRÅ crime data yet (that's a future task). But we already have area-level indicators that correlate strongly with safety:

- `low_economic_standard_pct` (negative) — poverty correlates with crime
- `employment_rate` (positive) — unemployment correlates with crime
- `education_below_secondary_pct` (negative)
- Police vulnerability classifications (when ingested)
- Negative POI density (from proximity task)

### 1.2 Compute a Safety Proxy

Create a derived safety signal from existing indicators. This is a temporary measure until BRÅ data is integrated, at which point actual crime rates replace the proxy.

```php
class SafetyScoreService
{
    /**
     * Returns a safety score 0.0 (worst) to 1.0 (safest) for a DeSO.
     * Uses available indicators as proxy until crime data is integrated.
     */
    public function forDeso(string $desoCode, int $year): float
    {
        $indicators = IndicatorValue::where('deso_code', $desoCode)
            ->where('year', $year)
            ->whereHas('indicator', fn($q) => $q->whereIn('slug', [
                'employment_rate',
                'low_economic_standard_pct',
                'education_below_secondary_pct',
            ]))
            ->with('indicator')
            ->get()
            ->keyBy(fn($iv) => $iv->indicator->slug);

        // Weighted combination (higher = safer)
        $safetySignals = [];

        if ($emp = $indicators->get('employment_rate')) {
            $safetySignals[] = ['value' => $emp->normalized_value, 'weight' => 0.4];
        }
        if ($les = $indicators->get('low_economic_standard_pct')) {
            // Invert: high poverty = low safety
            $safetySignals[] = ['value' => 1.0 - $les->normalized_value, 'weight' => 0.35];
        }
        if ($edu = $indicators->get('education_below_secondary_pct')) {
            $safetySignals[] = ['value' => 1.0 - $edu->normalized_value, 'weight' => 0.25];
        }

        if (empty($safetySignals)) return 0.5; // Default if no data

        $weighted = collect($safetySignals)->sum(fn($s) => $s['value'] * $s['weight']);
        $totalWeight = collect($safetySignals)->sum('weight');

        return $weighted / $totalWeight;
    }

    /**
     * When BRÅ crime data is available, this replaces the proxy.
     */
    public function forDesoWithCrime(string $desoCode, int $year): float
    {
        // TODO: Use actual crime_rate indicator when BRÅ task is complete
        // For now, delegate to proxy
        return $this->forDeso($desoCode, $year);
    }
}
```

### 1.3 When BRÅ Lands

When crime data is ingested (separate task), add the actual crime indicators to the safety computation with dominant weight:

```php
// Future: with crime data
$safetySignals = [
    ['value' => 1.0 - $crimeRate->normalized_value, 'weight' => 0.50],  // Actual crime dominates
    ['value' => $emp->normalized_value, 'weight' => 0.20],
    ['value' => 1.0 - $les->normalized_value, 'weight' => 0.20],
    ['value' => 1.0 - $edu->normalized_value, 'weight' => 0.10],
];
```

The safety proxy should live in its own service so swapping the data source is a one-file change.

---

## Step 2: Per-Category Safety Sensitivity

### 2.1 The Core Insight

Not all amenities are equally affected by safety. A hierarchy of sensitivity:

| Sensitivity | Amenity Type | Why | Safety Multiplier |
|---|---|---|---|
| Very low | Grocery | Necessity. You go regardless. Usually daytime, quick trip. | 0.3 |
| Low | Transit stop | Necessity. Fixed schedule forces you. Usually near other people. | 0.5 |
| Medium | School | Kids walk there daily. Parents care enormously about route safety. | 0.8 |
| Medium | Green space / park | Discretionary. People avoid parks they feel unsafe in. | 1.0 |
| High | Fitness / gym | Discretionary. Often evening hours. | 1.0 |
| High | Café / restaurant | Discretionary. Especially evening. People avoid areas that feel hostile. | 1.2 |
| Very high | Cinema / entertainment | Discretionary. Night-time. Long walk home in the dark. | 1.5 |
| Very high | Nightlife / bars | Discretionary. Late night. Most vulnerable to street crime. | 1.5 |

The **safety multiplier** determines how much the safety penalty amplifies the effective distance for that amenity type. Grocery at 0.3 means safety barely affects grocery proximity. Cinema at 1.5 means safety has an outsized effect on entertainment proximity.

### 2.2 Database: Category Safety Sensitivity

Add a `safety_sensitivity` column to the POI category configuration. This should be admin-tunable, not hardcoded.

**Option A: Add to existing POI categories table (if one exists)**

```php
// Migration
Schema::table('poi_categories', function (Blueprint $table) {
    $table->decimal('safety_sensitivity', 4, 2)->default(1.0);
    // 0.0 = safety doesn't affect this category at all
    // 1.0 = standard safety penalty
    // 1.5 = extra sensitive to safety (discretionary, night-time)
});
```

**Option B: If POI categories are just strings, create a config table**

```php
Schema::create('poi_category_settings', function (Blueprint $table) {
    $table->id();
    $table->string('category', 40)->unique();          // Matches pois.category
    $table->string('label');                            // Display name
    $table->string('label_sv')->nullable();             // Swedish display name
    $table->string('signal', 20)->default('positive');  // positive, negative, neutral
    $table->decimal('safety_sensitivity', 4, 2)->default(1.0);
    $table->integer('max_distance_m')->default(1000);   // Decay max distance
    $table->string('icon', 10)->nullable();             // Emoji for display
    $table->boolean('is_active')->default(true);
    $table->integer('display_order')->default(0);
    $table->timestamps();
});
```

Seed with initial values:

```php
$categories = [
    ['category' => 'grocery', 'label' => 'Grocery Store', 'label_sv' => 'Livsmedel', 'signal' => 'positive', 'safety_sensitivity' => 0.3, 'max_distance_m' => 1000, 'icon' => '🛒'],
    ['category' => 'transit_stop', 'label' => 'Transit Stop', 'label_sv' => 'Hållplats', 'signal' => 'positive', 'safety_sensitivity' => 0.5, 'max_distance_m' => 1000, 'icon' => '🚇'],
    ['category' => 'school', 'label' => 'School', 'label_sv' => 'Skola', 'signal' => 'positive', 'safety_sensitivity' => 0.8, 'max_distance_m' => 2000, 'icon' => '🏫'],
    ['category' => 'park', 'label' => 'Park / Green Space', 'label_sv' => 'Grönområde', 'signal' => 'positive', 'safety_sensitivity' => 1.0, 'max_distance_m' => 1000, 'icon' => '🌳'],
    ['category' => 'fitness', 'label' => 'Gym / Sports', 'label_sv' => 'Gym / Sport', 'signal' => 'positive', 'safety_sensitivity' => 1.0, 'max_distance_m' => 1000, 'icon' => '🏃'],
    ['category' => 'cafe', 'label' => 'Café / Restaurant', 'label_sv' => 'Café / Restaurang', 'signal' => 'positive', 'safety_sensitivity' => 1.2, 'max_distance_m' => 1000, 'icon' => '☕'],
    ['category' => 'entertainment', 'label' => 'Cinema / Culture', 'label_sv' => 'Bio / Kultur', 'signal' => 'positive', 'safety_sensitivity' => 1.5, 'max_distance_m' => 1500, 'icon' => '🎭'],
    ['category' => 'nightlife', 'label' => 'Bars / Nightlife', 'label_sv' => 'Nattliv', 'signal' => 'neutral', 'safety_sensitivity' => 1.5, 'max_distance_m' => 1000, 'icon' => '🍸'],
    ['category' => 'gambling', 'label' => 'Gambling Venue', 'label_sv' => 'Spelställe', 'signal' => 'negative', 'safety_sensitivity' => 0.0, 'max_distance_m' => 500, 'icon' => '🎰'],
    ['category' => 'pawnbroker', 'label' => 'Pawn Shop', 'label_sv' => 'Pantbank', 'signal' => 'negative', 'safety_sensitivity' => 0.0, 'max_distance_m' => 500, 'icon' => '💸'],
];
```

**Note:** Negative POIs have `safety_sensitivity = 0.0`. Their "badness" doesn't get worse in unsafe areas — they're already bad. The safety modulation only applies to positive/neutral amenities where the question is "does being nearby actually help you?"

---

## Step 3: The Modified Decay Function

### 3.1 Current Decay (from proximity task)

```
decay(distance, max_distance) = max(0, 1 - distance / max_distance)
```

### 3.2 New: Safety-Modulated Decay

```
risk_penalty = (1 - safety_score) × safety_sensitivity

effective_distance = physical_distance × (1 + risk_penalty)

decay(effective_distance, max_distance) = max(0, 1 - effective_distance / max_distance)
```

Where:
- `safety_score`: 0.0 (worst) to 1.0 (safest), from SafetyScoreService
- `safety_sensitivity`: per-category multiplier from poi_category_settings (0.0–1.5)
- `risk_penalty`: how much the distance inflates (0 in safe areas, up to 1.5 in worst areas for high-sensitivity categories)

### 3.3 Examples

**Djursholm (safety = 0.95):**
```
Cinema 500m away, safety_sensitivity = 1.5
risk_penalty = (1 - 0.95) × 1.5 = 0.075
effective_distance = 500 × 1.075 = 537m
decay = 1 - 537/1500 = 0.64
→ Barely affected. Safe area, amenity works as expected.
```

**Kronoparken (safety = 0.15):**
```
Cinema 500m away, safety_sensitivity = 1.5
risk_penalty = (1 - 0.15) × 1.5 = 1.275
effective_distance = 500 × 2.275 = 1137m
decay = 1 - 1137/1500 = 0.24
→ Heavily penalized. Cinema might as well be 1km+ away.
```

**Kronoparken, grocery instead:**
```
Grocery 500m away, safety_sensitivity = 0.3
risk_penalty = (1 - 0.15) × 0.3 = 0.255
effective_distance = 500 × 1.255 = 627m
decay = 1 - 627/1000 = 0.37
→ Moderate penalty. Grocery still matters, but less than in a safe area.
```

**Net effect on Kronoparken's proximity score:**

| Category | Before (no safety) | After (safety-modulated) |
|---|---|---|
| Grocery 500m | 50/100 | 37/100 |
| Transit 300m | 70/100 | 53/100 |
| Park 200m | 80/100 | 43/100 |
| Cinema 500m | 67/100 | 24/100 |
| School 800m | 60/100 | 22/100 |
| **Proximity composite** | **~65** | **~35** |

The proximity score drops from 65 to 35. With the 70/30 area/proximity blend, if the area score is 18 (terrible):
- Before: `18 × 0.70 + 65 × 0.30 = 32` — misleadingly high
- After: `18 × 0.70 + 35 × 0.30 = 23` — more honest

The area is bad. Nearby amenities don't save it. The score reflects reality.

---

## Step 4: Modify ProximityScoreService

### 4.1 Inject Safety

The `ProximityScoreService` from the base task needs one addition: it fetches the safety score for the pin's DeSO and passes it to each category scorer.

```php
class ProximityScoreService
{
    public function __construct(
        private SafetyScoreService $safety,
    ) {}

    public function score(float $lat, float $lng): ProximityResult
    {
        // Resolve DeSO for the pin
        $deso = DB::selectOne("
            SELECT deso_code FROM deso_areas
            WHERE ST_Contains(geom, ST_SetSRID(ST_MakePoint(?, ?), 4326))
            LIMIT 1
        ", [$lng, $lat]);

        $safetyScore = $deso
            ? $this->safety->forDeso($deso->deso_code, now()->year - 1)
            : 0.5;

        return new ProximityResult(
            school: $this->scoreSchool($lat, $lng, $safetyScore),
            greenSpace: $this->scoreGreenSpace($lat, $lng, $safetyScore),
            transit: $this->scoreTransit($lat, $lng, $safetyScore),
            grocery: $this->scoreGrocery($lat, $lng, $safetyScore),
            negativePoi: $this->scoreNegativePois($lat, $lng),  // No safety mod
            positivePoi: $this->scorePositivePois($lat, $lng, $safetyScore),
            safetyScore: $safetyScore,
        );
    }
}
```

### 4.2 Modified Decay Helper

```php
private function decayWithSafety(
    float $physicalDistanceM,
    float $maxDistanceM,
    float $safetyScore,
    float $safetySensitivity,
): float {
    $riskPenalty = (1.0 - $safetyScore) * $safetySensitivity;
    $effectiveDistance = $physicalDistanceM * (1.0 + $riskPenalty);

    return max(0.0, 1.0 - $effectiveDistance / $maxDistanceM);
}
```

Every category scorer replaces its `max(0, 1 - distance / max)` call with `$this->decayWithSafety(...)`, passing the category's `safety_sensitivity` from `poi_category_settings`.

### 4.3 Load Category Settings

```php
private function getCategorySettings(): Collection
{
    return Cache::remember('poi_category_settings', 3600, function () {
        return PoiCategorySetting::all()->keyBy('category');
    });
}
```

Each proximity scorer reads `safety_sensitivity` and `max_distance_m` from the settings table instead of hardcoding.

---

## Step 5: Admin Dashboard — POI Category Management

### 5.1 New Admin Page

Add `/admin/poi-categories` to the admin dashboard. This is where the operator tunes safety sensitivity, max distances, and active status per POI category.

```
┌──────────────────────────────────────────────────────────────────┐
│  POI Category Settings                                          │
│                                                                  │
│  Category       Signal    Safety     Max Dist   Active          │
│                          Sensitivity                             │
│  ─────────────────────────────────────────────────────────────   │
│  🛒 Livsmedel   ● pos    [0.30]     [1000]m    ☑              │
│  🚇 Hållplats   ● pos    [0.50]     [1000]m    ☑              │
│  🏫 Skola       ● pos    [0.80]     [2000]m    ☑              │
│  🌳 Grönområde  ● pos    [1.00]     [1000]m    ☑              │
│  🏃 Gym/Sport   ● pos    [1.00]     [1000]m    ☑              │
│  ☕ Café/Rest.  ● pos    [1.20]     [1000]m    ☑              │
│  🎭 Bio/Kultur  ● pos    [1.50]     [1500]m    ☑              │
│  🍸 Nattliv     ○ neut   [1.50]     [1000]m    ☑              │
│  🎰 Spelställe  ● neg    [0.00]     [ 500]m    ☑              │
│  💸 Pantbank    ● neg    [0.00]     [ 500]m    ☑              │
│                                                                  │
│  ── Explanation ────────────────────────────────────────────     │
│                                                                  │
│  Safety sensitivity controls how much the area's safety          │
│  score affects the proximity value of each amenity type.         │
│                                                                  │
│  0.0 = Safety doesn't affect this category at all                │
│  1.0 = Standard safety penalty                                   │
│  1.5 = Extra sensitive (discretionary, evening/night use)        │
│                                                                  │
│  In an area with safety score 0.15 (very unsafe):                │
│  • Grocery (0.3): 500m feels like 627m                          │
│  • Park (1.0): 500m feels like 925m                             │
│  • Cinema (1.5): 500m feels like 1137m                          │
│                                                                  │
│  [  Save Changes  ]     [  Recompute Example  ]                  │
│                                                                  │
└──────────────────────────────────────────────────────────────────┘
```

### 5.2 Routes & Controller

```php
Route::prefix('admin')->group(function () {
    // Existing
    Route::get('/indicators', [AdminIndicatorController::class, 'index']);
    Route::put('/indicators/{indicator}', [AdminIndicatorController::class, 'update']);

    // New
    Route::get('/poi-categories', [AdminPoiCategoryController::class, 'index'])
        ->name('admin.poi-categories');
    Route::put('/poi-categories/{category}', [AdminPoiCategoryController::class, 'update'])
        ->name('admin.poi-categories.update');
});
```

### 5.3 AdminPoiCategoryController

```php
class AdminPoiCategoryController extends Controller
{
    public function index()
    {
        $categories = PoiCategorySetting::orderBy('display_order')->get();

        // Example computation for the "Recompute Example" panel
        $exampleSafe = $this->exampleComputation($categories, 0.90);   // Djursholm
        $exampleUnsafe = $this->exampleComputation($categories, 0.15); // Kronoparken

        return Inertia::render('Admin/PoiCategories', [
            'categories' => $categories,
            'example_safe' => $exampleSafe,
            'example_unsafe' => $exampleUnsafe,
        ]);
    }

    public function update(Request $request, PoiCategorySetting $category)
    {
        $validated = $request->validate([
            'safety_sensitivity' => 'required|numeric|min:0|max:3',
            'max_distance_m' => 'required|integer|min:100|max:5000',
            'is_active' => 'required|boolean',
            'signal' => 'required|in:positive,negative,neutral',
        ]);

        $category->update($validated);

        // Clear the cache
        Cache::forget('poi_category_settings');

        return back();
    }

    /**
     * Compute example effective distances for the admin preview panel.
     */
    private function exampleComputation(Collection $categories, float $safetyScore): array
    {
        $physicalDistance = 500; // 500m for all examples

        return $categories->map(fn($cat) => [
            'category' => $cat->category,
            'label' => $cat->label_sv ?? $cat->label,
            'physical_m' => $physicalDistance,
            'effective_m' => round($physicalDistance * (1 + (1 - $safetyScore) * $cat->safety_sensitivity)),
            'decay' => round(max(0, 1 - ($physicalDistance * (1 + (1 - $safetyScore) * $cat->safety_sensitivity)) / $cat->max_distance_m), 2),
        ])->toArray();
    }
}
```

### 5.4 Live Preview

The admin page should have a "Recompute Example" feature that shows how the current settings would affect two reference areas:

```
┌──────────────────────────────────────────────────────────────┐
│  Live Preview: 500m to each amenity                          │
│                                                              │
│              Djursholm (safety: 0.90)  Kronoparken (0.15)    │
│  Grocery:    507m → decay 0.49       627m → decay 0.37      │
│  Transit:    525m → decay 0.47       925m → decay 0.08      │
│  School:     540m → decay 0.73       1180m → decay 0.41     │
│  Park:       550m → decay 0.45       925m → decay 0.07      │
│  Cinema:     575m → decay 0.62       1137m → decay 0.24     │
│                                                              │
│  Proximity composite:    72              34                  │
└──────────────────────────────────────────────────────────────┘
```

This gives the operator immediate feedback when tuning the sensitivity sliders. Change cinema from 1.5 to 2.0 → see the Kronoparken column update instantly.

---

## Step 6: Transparency in Reports

### 6.1 Show the Safety Modulation

When the report is generated (from the report task), include the safety modulation in the proximity section:

```
── Närhetsanalys ─────────────────────────────────

⚠️ Trygghetspoängen för detta område (0.15) påverkar
   hur mycket närliggande service bidrar till poängen.

🌳 Grönområde                              43/100
   Kronoparken stadspark — 200m (effektivt: 370m)
   Poängen justerad ned pga låg trygghetspoäng
```

Show both physical and effective distance. The user sees: "The park is 200m away, but the model treats it as 370m because of the safety situation." This is honest and builds trust.

### 6.2 Sidebar Indicator

Add a safety badge to the proximity section header:

```
── Närhetsanalys ──  Trygghetszone: ⚠️ Låg
```

Three tiers:
- ✅ Hög (safety > 0.65) — no modulation text needed
- ⚠️ Medel (0.35–0.65) — "Trygghetspoängen minskar närhetsvärdet något"
- 🔴 Låg (< 0.35) — "Trygghetspoängen minskar närhetsvärdet väsentligt"

---

## Implementation Order

### Phase A: Safety Service
1. Create `SafetyScoreService` with proxy based on existing indicators
2. Create `poi_category_settings` migration + seeder
3. Create `PoiCategorySetting` model
4. Test: `php artisan tinker` → compute safety for known DeSOs (Danderyd ≈ 0.9, Rinkeby ≈ 0.15)

### Phase B: Modified Decay
5. Add `decayWithSafety()` helper to `ProximityScoreService`
6. Modify each category scorer to use safety-modulated decay
7. Load category settings from database (cached)
8. Test: compare proximity scores before/after for Kronoparken, Djursholm, central Stockholm

### Phase C: Admin Dashboard
9. Create `AdminPoiCategoryController`
10. Create `Admin/PoiCategories.tsx` page
11. Add live preview computation
12. Wire up save → cache clear

### Phase D: Report Integration
13. Include safety_score in ProximityResult
14. Show effective distance in report proximity section
15. Add safety zone badge to sidebar

---

## Verification

### Spot Checks

| Location | Safety Score | Cinema 500m (before) | Cinema 500m (after) | Grocery 500m (before) | Grocery 500m (after) |
|---|---|---|---|---|---|
| Djursholm | ~0.90 | 67 | 63 | 50 | 48 |
| Central Stockholm | ~0.70 | 67 | 54 | 50 | 45 |
| Average suburb | ~0.50 | 67 | 42 | 50 | 40 |
| Kronoparken | ~0.15 | 67 | 24 | 50 | 37 |
| Rinkeby | ~0.10 | 67 | 19 | 50 | 35 |

Cinema drops much more sharply than grocery. That's the correct behavior.

### Admin Dashboard
- [ ] `/admin/poi-categories` loads with all categories
- [ ] Changing safety_sensitivity and saving updates the database
- [ ] Live preview recalculates when values change
- [ ] Djursholm column barely changes; Kronoparken column changes dramatically
- [ ] Cache is cleared after save (next proximity query uses new values)

### Edge Cases
- [ ] DeSO with no safety indicators → falls back to 0.5 (neutral)
- [ ] safety_sensitivity = 0.0 → effective_distance = physical_distance (no modulation)
- [ ] safety_sensitivity = 3.0 (max) → doesn't produce negative distances or NaN
- [ ] Very short distance (10m) in very unsafe area → still produces some score (you live next door)

---

## What NOT to Do

- **DO NOT use safety modulation on negative POIs.** A pawn shop near an unsafe area is already penalized by being a negative POI. Don't double-dip.
- **DO NOT hardcode sensitivity values.** They must come from the database so the admin can tune them without code changes.
- **DO NOT show "safety score" as a named user-facing metric.** It's a computational input, not a product feature. The user sees "Trygghetszone: Låg" as a contextual note, not "Safety Score: 0.15." We're in legally sensitive territory with area safety labels.
- **DO NOT block the proximity task on this.** The base proximity scoring ships first with flat decay. This task layers safety modulation on top. Both work independently.
- **DO NOT over-penalize.** The maximum `risk_penalty` for the worst area with the highest sensitivity should inflate distance by ~2.5×, not 10×. A grocery store 200m away in Rinkeby should still register as somewhat useful, not zero.
- **DO NOT use this as a substitute for actual crime data.** The proxy (employment + poverty + education) captures ~60-70% of the safety variance. When BRÅ data arrives, swap it in. The service interface stays the same.

**DO:**
- Make everything admin-tunable (sensitivity, max_distance, signal, active status)
- Show the operator live examples of how settings affect real areas
- Be transparent in reports: show both physical and effective distance
- Cache category settings aggressively (they change rarely)
- Design the SafetyScoreService interface so BRÅ data drops in cleanly later