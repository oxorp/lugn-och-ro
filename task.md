# TASK: Urbanity-Aware Proximity Radii

## Depends On

- Proximity scoring task (completed)
- Urbanity classification task (must land first — `deso_areas.urbanity_tier` must be populated)

## The Problem

The proximity config uses flat radii nationally:

```php
'scoring_radii' => [
    'school'       => 2000,
    'green_space'  => 1500,
    'transit'      => 1000,
    'grocery'      => 1000,
    'negative_poi' => 500,
    'positive_poi' => 1000,
],
```

This means a school at 1.8km scores the same whether the pin is in Södermalm (where there are 5 schools within 500m) or in rural Jämtland (where 1.8km IS the closest school and that's perfectly fine). The flat radius punishes rural areas for being rural and gives urban areas credit for density that's table stakes.

## The Fix

Replace flat radii with per-urbanity-tier radii. The decay function stays identical — only the max distance changes based on where the pin is.

---

## Step 1: Update Config

```php
// config/proximity.php

'scoring_radii' => [
    'school' => [
        'urban'      => 1500,
        'semi_urban' => 2000,
        'rural'      => 3500,
    ],
    'green_space' => [
        'urban'      => 1000,
        'semi_urban' => 1500,
        'rural'      => 2500,
    ],
    'transit' => [
        'urban'      => 800,
        'semi_urban' => 1200,
        'rural'      => 2500,
    ],
    'grocery' => [
        'urban'      => 800,
        'semi_urban' => 1200,
        'rural'      => 2000,
    ],
    'negative_poi' => [
        'urban'      => 400,
        'semi_urban' => 500,
        'rural'      => 500,
    ],
    'positive_poi' => [
        'urban'      => 800,
        'semi_urban' => 1000,
        'rural'      => 1500,
    ],
],

// Query radii also need urbanity awareness
'school_query_radius' => [
    'urban'      => 1500,
    'semi_urban' => 2000,
    'rural'      => 3500,
],
'poi_query_radius' => [
    'urban'      => 1000,
    'semi_urban' => 1500,
    'rural'      => 2500,
],
'display_radius' => [
    'urban'      => 1500,
    'semi_urban' => 2000,
    'rural'      => 3500,
],
```

### Rationale for Each Category

**School (1500 / 2000 / 3500):**
Urban — 1.5km is a 18-minute walk. In Stockholm there are multiple schools within that. Anything beyond is a different neighborhood's school.
Semi-urban — 2km, ~25 minutes. Standard suburban school catchment.
Rural — 3.5km. Many rural families drive or cycle to school. A school within 3.5km is genuinely "your school."

**Green space (1000 / 1500 / 2500):**
Urban — parks are everywhere, 1km is generous. If there's no green space within 1km in a city, that's a real gap.
Rural — nature is the default. "Distance to park" barely makes sense when you're surrounded by forest. 2.5km catches the nearest organized recreation area.

**Transit (800 / 1200 / 2500):**
Urban — 800m to a stop is already far for a city. Stockholm aims for 400m max. Anything beyond 800m means you're walking 10+ minutes before even boarding.
Rural — 2.5km. A bus stop within 2.5km in rural Sweden is genuinely useful transit access. Many rural DeSOs have zero stops within 5km.

**Grocery (800 / 1200 / 2000):**
Urban — 800m covers multiple stores. Beyond that in a city is a grocery desert.
Rural — 2km to the nearest ICA Nära is completely normal and acceptable.

**Negative POIs (400 / 500 / 500):**
Tight everywhere. A pawn shop matters when it's on your block, not 1km away. Slightly tighter in urban areas because urban blocks are denser — 400m means several blocks.

**Positive POIs (800 / 1000 / 1500):**
Urban — cafés, gyms, restaurants within 800m. Standard walkable amenity radius.
Rural — 1.5km. The local gym or café being 1.2km away is fine.

---

## Step 2: Resolve Urbanity Tier in ProximityScoreService

The service already resolves the DeSO for the pin (for area score lookup). Add urbanity tier to that lookup:

```php
class ProximityScoreService
{
    public function score(float $lat, float $lng): ProximityResult
    {
        $deso = DB::selectOne("
            SELECT deso_code, urbanity_tier
            FROM deso_areas
            WHERE ST_Contains(geom, ST_SetSRID(ST_MakePoint(?, ?), 4326))
            LIMIT 1
        ", [$lng, $lat]);

        $urbanityTier = $deso->urbanity_tier ?? 'semi_urban'; // Default fallback
        $safetyScore = $deso
            ? $this->safety->forDeso($deso->deso_code, now()->year - 1)
            : 0.5;

        return new ProximityResult(
            school: $this->scoreSchool($lat, $lng, $urbanityTier, $safetyScore),
            greenSpace: $this->scoreGreenSpace($lat, $lng, $urbanityTier, $safetyScore),
            transit: $this->scoreTransit($lat, $lng, $urbanityTier, $safetyScore),
            grocery: $this->scoreGrocery($lat, $lng, $urbanityTier, $safetyScore),
            negativePoi: $this->scoreNegativePois($lat, $lng, $urbanityTier),
            positivePoi: $this->scorePositivePois($lat, $lng, $urbanityTier, $safetyScore),
            safetyScore: $safetyScore,
            urbanityTier: $urbanityTier,
        );
    }
}
```

## Step 3: Radius Lookup Helper

```php
private function getRadius(string $category, string $urbanityTier): float
{
    $radii = config("proximity.scoring_radii.{$category}");

    // Support both flat (legacy) and tiered config
    if (is_array($radii)) {
        return $radii[$urbanityTier] ?? $radii['semi_urban'] ?? 1000;
    }

    // Flat value — backward compatibility during migration
    return (float) $radii;
}
```

This handles both the old flat config and the new tiered config, so the migration is non-breaking. If someone hasn't updated their config file, everything still works with flat values.

## Step 4: Update Each Scorer

Every scorer method replaces its hardcoded or config-read max distance:

```php
private function scoreSchool(float $lat, float $lng, string $urbanityTier, float $safetyScore): ProximityFactor
{
    $maxDistance = $this->getRadius('school', $urbanityTier);

    $schools = DB::select("
        SELECT s.*, ss.merit_value_17,
               ST_Distance(s.geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) as distance_m
        FROM schools s
        LEFT JOIN school_statistics ss ON ss.school_unit_code = s.school_unit_code
        WHERE ST_DWithin(s.geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)
          AND s.status = 'active'
          AND s.type_of_schooling LIKE '%Grundskola%'
        ORDER BY distance_m
        LIMIT 10
    ", [$lng, $lat, $lng, $lat, $maxDistance]);

    // ... rest of scoring logic unchanged, but uses $maxDistance for decay
}
```

Same pattern for every other scorer. The only change per method is:
1. Accept `$urbanityTier` parameter
2. Call `$this->getRadius('{category}', $urbanityTier)` instead of hardcoded value
3. Pass that radius to both the PostGIS `ST_DWithin` query AND the decay function

## Step 5: Update Query Radii in LocationController

The `LocationController` also has query radii for what to display in the sidebar (schools, POIs near the pin). These need the same treatment:

```php
public function show(float $lat, float $lng)
{
    $deso = DB::selectOne("
        SELECT deso_code, urbanity_tier FROM deso_areas
        WHERE ST_Contains(geom, ST_SetSRID(ST_MakePoint(?, ?), 4326))
        LIMIT 1
    ", [$lng, $lat]);

    $urbanityTier = $deso->urbanity_tier ?? 'semi_urban';

    $schoolRadius = $this->getQueryRadius('school_query_radius', $urbanityTier);
    $poiRadius = $this->getQueryRadius('poi_query_radius', $urbanityTier);
    $displayRadius = $this->getQueryRadius('display_radius', $urbanityTier);

    $schools = DB::select("
        SELECT ... 
        WHERE ST_DWithin(geom::geography, ..., ?)
    ", [..., $schoolRadius]);

    // Return display_radius so frontend draws the correct circle
    return response()->json([
        // ... existing fields
        'display_radius' => $displayRadius,
        'urbanity_tier' => $urbanityTier,
    ]);
}

private function getQueryRadius(string $key, string $urbanityTier): int
{
    $config = config("proximity.{$key}");
    if (is_array($config)) {
        return $config[$urbanityTier] ?? $config['semi_urban'] ?? 2000;
    }
    return (int) $config;
}
```

## Step 6: Frontend — Dynamic Display Radius

The map currently draws a circle at a fixed radius when a pin is dropped. It now needs to use the `display_radius` from the API response:

```tsx
// When API returns, update the circle radius
const displayRadius = response.data.display_radius; // 1500, 2000, or 3500

// Update the OpenLayers circle feature
circleFeature.setGeometry(
    circular(fromLonLat([lng, lat]), displayRadius)
);
```

The circle visually communicates "this is how far we looked." In an urban area it's tight (1.5km), in a rural area it's wide (3.5km). The user sees the scope adapt to context.

## Step 7: Integration with Safety Modulation

If the safety-modulated proximity task has also landed, both systems compose cleanly:

```php
// In a scorer method:
$maxDistance = $this->getRadius('school', $urbanityTier);  // Urbanity sets the base radius
$decay = $this->decayWithSafety($distance, $maxDistance, $safetyScore, $safetySensitivity);
// Safety modulates effective distance within that radius
```

Urbanity determines the field of play (how far is "reasonable"). Safety determines the penalty within that field. They're orthogonal.

**Example — school 2km away:**

| Scenario | Urbanity radius | Safety | Effective distance | Decay |
|---|---|---|---|---|
| Urban Stockholm, safe | 1500m | 0.90 | 2016m | 0.00 (beyond radius!) |
| Urban Stockholm, unsafe | 1500m | 0.15 | 3360m | 0.00 |
| Suburban, safe | 2000m | 0.80 | 2032m | 0.00 (barely beyond) |
| Suburban, unsafe | 2000m | 0.30 | 3120m | 0.00 |
| Rural, safe | 3500m | 0.85 | 2024m | 0.42 |
| Rural, normal | 3500m | 0.50 | 2800m | 0.20 |

The same 2km school: invisible in urban (beyond radius), marginal in suburban, solid in rural. That's exactly right.

---

## Step 8: Admin Dashboard Integration

If `poi_category_settings` exists (from safety task), add `max_distance_urban`, `max_distance_semi_urban`, `max_distance_rural` columns:

```php
Schema::table('poi_category_settings', function (Blueprint $table) {
    $table->renameColumn('max_distance_m', 'max_distance_semi_urban');
    $table->integer('max_distance_urban')->after('max_distance_semi_urban')->nullable();
    $table->integer('max_distance_rural')->after('max_distance_semi_urban')->nullable();
});

// Backfill: urban = semi_urban × 0.7, rural = semi_urban × 2.0 (rough starting points)
DB::statement("
    UPDATE poi_category_settings SET
        max_distance_urban = ROUND(max_distance_semi_urban * 0.7),
        max_distance_rural = ROUND(max_distance_semi_urban * 2.0)
");
```

The admin dashboard shows three columns instead of one:

```
Category       Signal    Safety    Urban    Semi     Rural    Active
                        Sens.
─────────────────────────────────────────────────────────────────
🛒 Livsmedel   ● pos    0.30     [800]m   [1200]m  [2000]m  ☑
🚇 Hållplats   ● pos    0.50     [800]m   [1200]m  [2500]m  ☑
🏫 Skola       ● pos    0.80     [1500]m  [2000]m  [3500]m  ☑
🌳 Grönområde  ● pos    1.00     [1000]m  [1500]m  [2500]m  ☑
```

**If `poi_category_settings` doesn't exist yet:** use the config file. The `getRadius()` helper reads from config. When the admin table eventually takes over, the helper reads from database instead. Same interface, different storage backend.

---

## Verification

### Spot Checks

Drop a pin in each context and verify the API response:

| Location | Expected tier | School radius | Grocery radius | Display circle |
|---|---|---|---|---|
| Sveavägen, Stockholm | urban | 1500m | 800m | 1500m |
| Sundbyberg centrum | urban | 1500m | 800m | 1500m |
| Kungsbacka centrum | semi_urban | 2000m | 1200m | 2000m |
| Borgholm, Öland | semi_urban | 2000m | 1200m | 2000m |
| Dorotea, Västerbotten | rural | 3500m | 2000m | 3500m |
| Arjeplog inland | rural | 3500m | 2000m | 3500m |

### Score Impact

| Scenario | Before (flat 2000m) | After (tiered) |
|---|---|---|
| School 1.6km, urban Stockholm | decay = 0.20 (within radius) | decay = 0.00 (beyond 1500m!) |
| School 1.6km, rural Jämtland | decay = 0.20 | decay = 0.54 (within 3500m, healthy score) |
| Grocery 900m, urban Göteborg | decay = 0.10 (within 1000m) | decay = 0.00 (beyond 800m!) |
| Grocery 900m, semi-urban | decay = 0.10 | decay = 0.25 (within 1200m) |
| Grocery 1.5km, rural | decay = 0.00 (beyond 1000m) | decay = 0.25 (within 2000m) |

Urban gets stricter — 900m to grocery is not great in a city. Rural gets more forgiving — same 900m is fine in the countryside. This is correct.

### Edge Cases

- [ ] Pin outside any DeSO (ocean, border) → falls back to `semi_urban`
- [ ] DeSO with `urbanity_tier = NULL` → falls back to `semi_urban`
- [ ] Old flat config format still works (backward compatibility via `getRadius` helper)
- [ ] Display circle on map changes size based on urbanity tier
- [ ] Safety modulation and urbanity radii compose correctly (no double-counting, no conflicts)

---

## What NOT to Do

- **DO NOT change the decay function shape.** Linear decay stays linear. Only the max distance changes.
- **DO NOT add more than 3 tiers.** Urban / semi-urban / rural is sufficient. "Wilderness" adds complexity for ~200 DeSOs where nobody is buying property anyway.
- **DO NOT hardcode radii in the scorer methods.** Everything comes from config or database. Admin-tunable.
- **DO NOT break backward compatibility.** The `getRadius` helper handles both flat numbers and tier objects. Existing tests pass without config changes.
- **DO NOT change the scoring formula.** The score for a school at 500m in urban Stockholm should be identical to before — only the cutoff distance (where score hits zero) changes.