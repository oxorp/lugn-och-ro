# TASK: Indicator Architecture Cleanup — Categories, POI Roles & Per-School Data

## The Problems

### Problem 1: Category Chaos

We have **11 backend categories** (income, employment, education, demographics, housing, crime, safety, financial_distress, amenities, transport, proximity) trying to collapse into **4 display groups** in the sidebar. The math doesn't add up — the sidebar shows "14 indicators" but some categories are orphaned or invisible. Users see a disjointed experience: the admin has 11 groups, the sidebar has 4, and neither tells a clean story.

### Problem 2: POI Double Counting

POIs currently serve two different purposes with no clear boundary:

**Role A — Area-level density indicators:** "This DeSO has 15.1 restaurants per km²" → stored as `restaurant_density` in `indicator_values`, feeds the composite area score. Computed per DeSO. 7 indicators (grocery, healthcare, restaurant, fitness, gambling, pawn_shop, fast_food).

**Role B — Pin-level proximity scores:** "The nearest grocery store is 340m from your pin" → computed on-the-fly per address. 6 proximity indicators (school, green_space, transit, grocery, negative_poi, positive_poi).

The problem: `grocery_density` (Role A) and `prox_grocery` (Role B) both measure "grocery access" but at different scales, with different data, contributing to different scores. When a user buys a report for Sveavägen 42, which one matters? Both? How do they interact? Right now, this is undefined.

### Problem 3: School Data is DeSO-Aggregated

The current school indicators (`school_merit_value_avg`, `school_goal_achievement_avg`, `school_teacher_certification_avg`) average all grundskola statistics within a DeSO. For an address-based report, this is wrong:

- A pin at the edge of a DeSO might be 200m from an excellent school in the NEXT DeSO
- A large DeSO might contain both a great school and a terrible one — the average hides both
- "Average merit value in this DeSO: 221" is useless compared to "Årstaskolan (450m): 241, Eriksdalsskolan (1.2km): 198"

Reports need per-school data within a radius, not DeSO averages.

### Problem 4: Proximity Indicators Have Zero Coverage

The admin shows 6 proximity indicators (prox_school, prox_green_space, etc.) all with "0 / 6160" coverage. They're defined but never computed. They were designed for a pin-based system that hasn't landed yet. Meanwhile they show up in indicator counts ("33 indicators") inflating the number without delivering value.

---

## The Solution: Five Clean Categories

### New Category Structure

Collapse everything into **5 user-facing categories** that match how people think about neighborhoods:

| # | Display Category | Swedish | What It Covers | Sources |
|---|---|---|---|---|
| 1 | **Safety & Crime** | Trygghet & brottslighet | Crime rates, perceived safety, police vulnerability, negative POI density | BRÅ, Polisen, BRÅ NTU, OSM |
| 2 | **Economy & Employment** | Ekonomi & arbetsmarknad | Income, employment, debt/financial distress, economic standard | SCB, Kronofogden |
| 3 | **Education & Schools** | Utbildning & skolor | School quality (per-school, not DeSO average), education levels in population | Skolverket, SCB |
| 4 | **Environment & Services** | Miljö & service | Green space, amenities, healthcare, grocery, restaurant/café density, transport, positive POI | OSM, GTFS, Trafiklab |
| 5 | **Proximity** | Platsanalys | Pin-specific: nearest school + distance, transit access, grocery access, green space access | Computed per address |

### Key Design Decisions

**Categories 1-4 are area-level.** They describe the DeSO/neighborhood. They feed the Area Score (70% of composite). They're pre-computed and cached. They're the same for everyone standing anywhere in the same DeSO.

**Category 5 is pin-level.** It describes THIS specific address. It feeds the Proximity Score (30% of composite). It's computed on-the-fly per pin drop. It changes every 100 meters.

**This maps to the two-layer scoring model:**
```
Area Score (70%) = f(Safety, Economy, Education, Environment)
Proximity Score (30%) = f(Platsanalys)
Final Score = Area × 0.70 + Proximity × 0.30
```

**The sidebar preview shows all 5 categories** — the first 4 describe "this neighborhood" and the 5th describes "this exact spot."

---

## Step 1: Reorganize Indicator Categories

### 1.1 Migration: Update Categories

```php
// Category mapping: old → new
$mapping = [
    // Safety & Crime
    'violent_crime_rate' => 'safety',
    'property_crime_rate' => 'safety',
    'total_crime_rate' => 'safety',
    'vulnerability_flag' => 'safety',
    'perceived_safety' => 'safety',
    'gambling_density' => 'safety',      // Was: amenities. Gambling = negative signal
    'pawn_shop_density' => 'safety',     // Was: amenities. Pawn shops = distress signal
    'fast_food_density' => 'safety',     // Was: amenities. Late-night clusters = disorder proxy

    // Economy & Employment
    'median_income' => 'economy',
    'low_economic_standard_pct' => 'economy',
    'employment_rate' => 'economy',
    'debt_rate_pct' => 'economy',         // Was: financial_distress
    'eviction_rate' => 'economy',         // Was: financial_distress
    'median_debt_sek' => 'economy',       // Was: financial_distress

    // Education & Schools
    'education_post_secondary_pct' => 'education',
    'education_below_secondary_pct' => 'education',
    'school_merit_value_avg' => 'education',
    'school_goal_achievement_avg' => 'education',
    'school_teacher_certification_avg' => 'education',

    // Environment & Services
    'grocery_density' => 'environment',    // Was: amenities
    'healthcare_density' => 'environment', // Was: amenities
    'restaurant_density' => 'environment', // Was: amenities
    'fitness_density' => 'environment',    // Was: amenities
    'transit_stop_density' => 'environment', // Was: transport

    // Contextual (no display category — used internally, not shown in sidebar)
    'foreign_background_pct' => 'contextual',
    'population' => 'contextual',
    'rental_tenure_pct' => 'contextual',

    // Proximity (pin-level, separate computation)
    'prox_school' => 'proximity',
    'prox_green_space' => 'proximity',
    'prox_transit' => 'proximity',
    'prox_grocery' => 'proximity',
    'prox_negative_poi' => 'proximity',
    'prox_positive_poi' => 'proximity',
];

foreach ($mapping as $slug => $category) {
    Indicator::where('slug', $slug)->update(['category' => $category]);
}
```

### 1.2 Display Category Config

```php
// config/display_categories.php

return [
    'safety' => [
        'label' => 'Trygghet & brottslighet',
        'label_short' => 'Trygghet',
        'emoji' => '🛡️',
        'icon' => 'shield',
        'display_order' => 1,
        'score_layer' => 'area',     // Feeds area score
        'description' => 'Brottsstatistik, upplevd trygghet och polisens klassificering',
    ],
    'economy' => [
        'label' => 'Ekonomi & arbetsmarknad',
        'label_short' => 'Ekonomi',
        'emoji' => '📊',
        'icon' => 'bar-chart-3',
        'display_order' => 2,
        'score_layer' => 'area',
        'description' => 'Inkomst, sysselsättning, skuldsättning och ekonomisk standard',
    ],
    'education' => [
        'label' => 'Utbildning & skolor',
        'label_short' => 'Utbildning',
        'emoji' => '🎓',
        'icon' => 'graduation-cap',
        'display_order' => 3,
        'score_layer' => 'area',
        'description' => 'Utbildningsnivå i befolkningen och skolkvalitet',
    ],
    'environment' => [
        'label' => 'Miljö & service',
        'label_short' => 'Service',
        'emoji' => '🌳',
        'icon' => 'trees',
        'display_order' => 4,
        'score_layer' => 'area',
        'description' => 'Grönområden, kollektivtrafik, mataffärer, sjukvård och restauranger',
    ],
    'proximity' => [
        'label' => 'Platsanalys',
        'label_short' => 'Plats',
        'emoji' => '📍',
        'icon' => 'map-pin',
        'display_order' => 5,
        'score_layer' => 'proximity',  // Feeds proximity score
        'description' => 'Avstånd till skolor, hållplatser, parker och service från din exakta adress',
    ],
    // 'contextual' is never displayed — internal use only
];
```

### 1.3 What Moved Where

| Indicator | Old Category | New Category | Why |
|---|---|---|---|
| gambling_density | amenities | safety | Gambling venues correlate with financial distress and disorder |
| pawn_shop_density | amenities | safety | Pawn shops are a distress signal, not a service amenity |
| fast_food_density | amenities | safety | Late-night fast food clusters proxy for disorder/nighttime issues |
| debt_rate_pct | financial_distress | economy | Debt is an economic indicator, not a separate domain |
| eviction_rate | financial_distress | economy | Same — economic stress |
| median_debt_sek | financial_distress | economy | Same |
| transit_stop_density | transport | environment | Transport is part of the service/infrastructure picture |
| foreign_background_pct | demographics | contextual | Used internally, never user-facing (legal sensitivity) |
| population | demographics | contextual | Contextual — not a quality indicator |
| rental_tenure_pct | housing | contextual | Contextual — tenure type informs the model but isn't a "score" |

### 1.4 Indicator Counts After Reorg

| Category | Count | Weight Budget |
|---|---|---|
| Safety | 8 (5 crime/safety + 3 negative POI density) | ~25% |
| Economy | 6 | ~20% |
| Education | 5 (2 population education + 3 school quality) | ~15% |
| Environment | 5 (4 positive amenity density + 1 transit density) | ~10% |
| Proximity | 6 (pin-level, separate computation) | 30% (proximity score) |
| Contextual | 3 (weight = 0, not displayed) | 0% |
| **Total** | **33** | **100%** |

The "33 datapunkter" stays accurate, but now every one of them belongs to a visible category (except the 3 contextual ones, which shouldn't be counted in the user-facing number — so really "30 indikatorer" in the CTA).

---

## Step 2: Clarify POI Architecture

### 2.1 The Two Roles, Formally Defined

**Role A: Area Density Indicators (DeSO-level)**

These answer: "What kind of neighborhood is this?"

POIs are counted per DeSO, normalized by area or population, stored as `indicator_values`. They feed the area score. They're the same for every address in the same DeSO.

| Indicator | POI Source | Category | Signal |
|---|---|---|---|
| grocery_density | OSM grocery stores | environment | Positive — daily service access |
| healthcare_density | OSM healthcare + pharmacy | environment | Positive — essential services |
| restaurant_density | OSM restaurants/cafés | environment | Positive — livability/gentrification |
| fitness_density | OSM gyms/sports | environment | Positive — active lifestyle |
| transit_stop_density | OSM/GTFS transit stops | environment | Positive — connectivity |
| gambling_density | OSM gambling venues | safety | Negative — financial distress marker |
| pawn_shop_density | OSM pawn shops | safety | Negative — distress marker |
| fast_food_density | OSM late-night fast food | safety | Negative — disorder proxy |

**Role B: Proximity Scores (Pin-level)**

These answer: "What's near THIS exact address?"

Computed on-the-fly when a pin is dropped. PostGIS distance queries within configurable radii. Feed the proximity score. Different for every address, even within the same DeSO.

| Factor | What It Measures | Radius |
|---|---|---|
| prox_school | Quality-weighted distance to nearest grundskola(s) | 2000m |
| prox_green_space | Distance to nearest park/green space | 1500m |
| prox_transit | Distance to nearest quality transit stop | 1000m |
| prox_grocery | Distance to nearest grocery store | 1000m |
| prox_negative_poi | Inverse density of nearby negative POIs | 500m |
| prox_positive_poi | Density of nearby positive POIs | 1000m |

### 2.2 No Double Counting

The concern: if `grocery_density` (area score) AND `prox_grocery` (proximity score) both measure grocery access, is the score double-counting?

**Answer: No, because they measure different things.**

- `grocery_density` = "this DeSO has lots of grocery stores relative to its size" (area characteristic)
- `prox_grocery` = "there's a Hemköp 340m from your front door" (personal convenience)

A DeSO can have high grocery density overall but your specific corner might be 1.2km from the nearest one (far side of a large DeSO). The area score says "good neighborhood for groceries." The proximity score says "but not from YOUR spot."

The weights handle this: area indicators get their weight within the 70% area budget, proximity factors get theirs within the 30% proximity budget. They're scored independently and combined at the end.

### 2.3 Diagram

```
POI Table (137,170 POIs)
    │
    ├──→ Ingestion aggregation (per DeSO, batch job)
    │       │
    │       └──→ indicator_values: grocery_density = 15.1/km²
    │            indicator_values: gambling_density = 0.3/km²
    │            ... (8 area density indicators)
    │            │
    │            └──→ Area Score (70%)
    │
    └──→ Proximity query (per pin, on-the-fly)
            │
            └──→ ST_DWithin(pin, poi, radius)
                 nearest grocery: 340m → score 0.66
                 nearest transit: 180m → score 0.82
                 ... (6 proximity factors)
                 │
                 └──→ Proximity Score (30%)

Area Score × 0.70 + Proximity Score × 0.30 = Final Score
```

---

## Step 3: Per-School Data for Reports

### 3.1 The Problem

Current school indicators are DeSO averages:
```
school_merit_value_avg for DeSO 0180C1030 = 221.4
```

This is useless for an address-based report. The user needs:
```
Schools near Sveavägen 42:
  Årstaskolan (grundskola, kommunal) — 450m — Meritvärde: 241
  Eriksdalsskolan (grundskola, kommunal) — 1.2km — Meritvärde: 198
  Södra Latin (grundskola, kommunal) — 1.8km — Meritvärde: 267
```

### 3.2 What We Already Have

The `schools` table has coordinates and DeSO assignment. The `school_statistics` table has per-school meritvärde, goal achievement, teacher certification, student count. The Skolverket API provides this data. **The data is already there** — we just aggregate it to DeSO level and throw away the per-school detail in the scoring.

### 3.3 What Needs to Change

**For the area score:** Keep the DeSO-level school indicators (`school_merit_value_avg` etc.) — they're valid as area characteristics. "The average school quality in this neighborhood" is a reasonable area-level metric.

**For the proximity score:** Replace the current `prox_school` (which is undefined / 0 coverage) with a real per-school proximity computation:

```php
// ProximityScoreService — school factor

public function computeSchoolProximity(float $lat, float $lng, float $radiusM = 2000): array
{
    // Find all grundskolor within radius, ordered by distance
    $schools = DB::select("
        SELECT
            s.school_unit_code,
            s.name,
            s.type_of_schooling,
            s.operator_type,
            ST_Distance(s.geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) as distance_m,
            ss.merit_value_17,
            ss.goal_achievement_pct,
            ss.teacher_certification_pct,
            ss.student_count
        FROM schools s
        LEFT JOIN school_statistics ss ON ss.school_unit_code = s.school_unit_code
            AND ss.academic_year = (
                SELECT MAX(academic_year) FROM school_statistics
                WHERE school_unit_code = s.school_unit_code
            )
        WHERE s.type_of_schooling LIKE '%Grundskola%'
          AND s.status = 'active'
          AND ST_DWithin(s.geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)
        ORDER BY distance_m ASC
    ", [$lng, $lat, $lng, $lat, $radiusM]);

    if (empty($schools)) {
        // No schools within radius — find the nearest one regardless of distance
        $nearest = DB::selectOne("
            SELECT
                s.name,
                ST_Distance(s.geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) as distance_m,
                ss.merit_value_17
            FROM schools s
            LEFT JOIN school_statistics ss ON ss.school_unit_code = s.school_unit_code
                AND ss.academic_year = (SELECT MAX(academic_year) FROM school_statistics WHERE school_unit_code = s.school_unit_code)
            WHERE s.type_of_schooling LIKE '%Grundskola%'
              AND s.status = 'active'
              AND s.geom IS NOT NULL
            ORDER BY s.geom::geography <-> ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
            LIMIT 1
        ", [$lng, $lat, $lng, $lat]);

        return [
            'score' => 0.0,
            'schools' => [],
            'nearest' => $nearest,
        ];
    }

    // Score: quality-weighted distance
    // Best nearby school matters most — use the highest-quality school within radius
    $bestMerit = collect($schools)->max('merit_value_17');
    $nearestDistance = $schools[0]->distance_m;

    // Distance decay + quality bonus
    $distanceScore = max(0, 1 - ($nearestDistance / $radiusM));
    $qualityScore = $bestMerit ? min(1, $bestMerit / 280) : 0.5; // 280 = "excellent"

    $score = ($distanceScore * 0.6) + ($qualityScore * 0.4);

    return [
        'score' => round($score, 3),
        'schools' => collect($schools)->map(fn($s) => [
            'name' => $s->name,
            'type' => $s->type_of_schooling,
            'operator' => $s->operator_type,
            'distance_m' => round($s->distance_m),
            'merit_value' => $s->merit_value_17,
            'goal_achievement' => $s->goal_achievement_pct,
            'teacher_certification' => $s->teacher_certification_pct,
            'student_count' => $s->student_count,
        ])->values()->all(),
    ];
}
```

### 3.4 Skolverket Per-School Data Availability

**Can we query per-school statistics from the API?**

Yes. Both Skolverket APIs support per-school lookups:

- **Skolenhetsregistret v2:** `GET /v2/school-units/{schoolUnitCode}` — metadata, location, type
- **Planned Educations v3:** `GET /v3/school-units/{schoolUnitCode}` — statistics including meritvärde

The `school_statistics` table already stores per-school data from these APIs. The ingestion command (`ingest:skolverket-stats`) fetches statistics per school unit code. **The per-school data is already in our database** — we just need to query it per-address instead of averaging it per-DeSO.

**What if a school has no statistics?** Many schools have `merit_value_17 = NULL` (Skolverket suppresses data for small cohorts, <15 students). The report should show "Inga publicerade meritvärden" for these schools. Don't exclude them — a school with no published data is still a school parents might use.

### 3.5 Report School Section

The report should show:

```
🎓 SKOLOR NÄRA DIG

Årstaskolan                                     450 m
  Grundskola · Kommunal · 342 elever
  Meritvärde: 241 (82:a percentilen)
  Måluppfyllelse: 94%  Lärarbehörighet: 78%

Eriksdalsskolan                                1.2 km
  Grundskola · Kommunal · 289 elever
  Meritvärde: 198 (34:e percentilen)
  Måluppfyllelse: 81%  Lärarbehörighet: 85%

Södra Latin                                    1.8 km
  Grundskola · Kommunal · 521 elever
  Meritvärde: 267 (96:e percentilen)
  Måluppfyllelse: 98%  Lärarbehörighet: 92%

Genomsnittligt meritvärde inom 2 km: 235 (72:a percentilen)
Bästa skolan: Södra Latin (267, 1.8 km)
```

### 3.6 Keep DeSO Averages for Area Score

Don't remove `school_merit_value_avg` from the area score. It serves a different purpose: "what's the general school quality level in this neighborhood?" It's valid as an area-level indicator and helps areas with many schools vs few schools compare fairly.

The per-school data goes in the **report** and feeds the **proximity score**. The DeSO average stays in the **area score**. Different layers, different roles.

---

## Step 4: Sidebar Teaser Update

### 4.1 Five Categories in the Teaser

```
🛡️ TRYGGHET & BROTTSLIGHET

Upplevd trygghet           ▓▓▓▓▓▓▓▓░░  42:a
Våldsbrott                 ▓▓░░░░░░░░  18:e
████████                   ░░░░░░░░░░  ███
████████████               ░░░░░░░░░░  ███
🔒 + 6 indikatorer i rapporten

Trygghetsbetyg baserat på brottsstatistik
och polisens klassificering av utsatta områden.


📊 EKONOMI & ARBETSMARKNAD

Medianinkomst              ▓▓▓▓▓▓▓▓▓░  78:e
Sysselsättningsgrad        ▓▓▓▓▓▓░░░░  61:a
████████████████           ░░░░░░░░░░  ███
████████████               ░░░░░░░░░░  ███
🔒 + 4 indikatorer i rapporten

Ekonomisk analys av inkomst, sysselsättning,
skuldsättning och ekonomisk standard.


🎓 UTBILDNING & SKOLOR

Meritvärde (skolor)        ▓▓▓▓▓▓▓▓▓░  91:a
Lärarbehörighet            ▓▓▓▓▓▓▓░░░  68:e
████████████████████       ░░░░░░░░░░  ███
🔒 + 3 indikatorer i rapporten
🏫 3 skolor inom 2 km — detaljer i rapporten

Skolanalys baserad på 7 507 grundskolor.


🌳 MILJÖ & SERVICE

Mataffärer                 ▓▓▓▓▓▓▓▓░░  71:a
Kollektivtrafik            ▓▓▓▓▓▓▓▓▓░  82:a
████████████               ░░░░░░░░░░  ███
████████████████           ░░░░░░░░░░  ███
🔒 + 3 indikatorer i rapporten

Baserat på 137 170 kartlagda servicepunkter.


📍 PLATSANALYS

Din exakta adress analyserad — avstånd till
närmaste skola, hållplats, park och mataffär.
🔒 6 platsspecifika analyser i rapporten
```

### 4.2 Free Preview Indicators — Updated Selection

| Category | Free 1 | Free 2 |
|---|---|---|
| Safety | Upplevd trygghet (NTU) | Våldsbrott |
| Economy | Medianinkomst | Sysselsättningsgrad |
| Education | Meritvärde (skolor) | Lärarbehörighet |
| Environment | Mataffärer | Kollektivtrafik |
| Proximity | *(No free values — the whole point is pin-specific detail)* | |

Proximity gets no free values because the entire category is the upsell — "we analyzed YOUR exact address." Showing a proximity value for free undermines the report's unique selling point.

### 4.3 CTA Summary — Updated

```
🔒 Se alla värden

30 indikatorer med exakta värden och percentiler
6 platsspecifika närhetsanalyser
3 skolor med meritvärden och avstånd

Lås upp — 79 kr
Engångsköp · Ingen prenumeration
```

Note: "30 indikatorer" not "33" — the 3 contextual ones (foreign_background, population, rental_tenure) are excluded from the user-facing count.

---

## Step 5: Admin Dashboard Updates

### 5.1 Group Indicators by New Categories

The admin indicator table should group by the 5 display categories + contextual. Each group shows:

```
🛡️ Trygghet & brottslighet  8 indicators · 5 active · weight: 25%
▼ Economy & arbetsmarknad   6 indicators · 6 active · weight: 20%
  ...
▼ Kontextuell              3 indicators · weight: 0% (ej visad)
```

### 5.2 Category Summary Row

Each category header shows total weight allocation for that category. This replaces the old single weight bar with a per-category breakdown.

---

## Step 6: Weight Rebalancing

With the new categories, suggested weights:

| Category | Indicators | Total Weight | Notes |
|---|---|---|---|
| **Safety** | violent_crime (0.06), property_crime (0.045), total_crime (0.025), vulnerability (0.095), perceived_safety (0.045), gambling (0.02), pawn_shop (0.01), fast_food (0.01) | **25%** | Biggest driver of "would I live here?" |
| **Economy** | median_income (0.065), low_economic_standard (0.04), employment (0.055), debt_rate (0.05), eviction_rate (0.03), median_debt (0.02) | **20%** | Financial health of the area |
| **Education** | post_secondary (0.038), below_secondary (0.022), merit_value (0.07), goal_achievement (0.045), teacher_certification (0.035) | **15%** | School quality + education level |
| **Environment** | grocery (0.04), healthcare (0.03), restaurant (0.02), fitness (0.02), transit_stop (0.04) | **10%** | Service level of the area |
| **Proximity** | school (0.10), green_space (0.04), transit (0.05), grocery (0.03), negative_poi (0.04), positive_poi (0.04) | **30%** | Pin-specific. This IS the product differentiator |
| **Contextual** | foreign_background, population, rental_tenure | **0%** | Internal model inputs, not scored |

Total: 100%

---

## Implementation Order

This task is a specification. Implementation should happen in this order:

### Phase A: Category Reorganization (Backend)
1. Run the category migration (update all indicator categories)
2. Update `config/display_categories.php`
3. Update admin dashboard to use new groupings
4. Update free preview indicator selection
5. Update sidebar teaser to show 5 categories

### Phase B: POI Architecture Clarification (Backend)
1. Document the dual role clearly in code comments and CLAUDE.md
2. Verify area density indicators are computed correctly per DeSO
3. Verify proximity indicators reference the same POI table
4. No structural code change needed — the architecture is sound, it just needs documentation

### Phase C: Per-School Proximity (Backend + Frontend)
1. Implement `ProximityScoreService::computeSchoolProximity()`
2. Update the report data structure to include per-school details
3. Update the proximity score computation to use real school data
4. Test: schools near pin should include schools from neighboring DeSOs

### Phase D: Weight Rebalancing
1. Update indicator weights via seeder/migration
2. Recompute all scores
3. Verify sanity (Danderyd green, Rinkeby purple)

---

## Verification

### Category Reorg
- [ ] All 33 indicators assigned to one of 6 categories (safety, economy, education, environment, proximity, contextual)
- [ ] No orphaned indicators (every slug in the mapping)
- [ ] Admin dashboard groups by new categories
- [ ] Sidebar teaser shows 5 categories (not contextual)
- [ ] CTA says "30 indikatorer" (excluding 3 contextual)

### POI Architecture
- [ ] Area density indicators (grocery_density etc.) are computed per DeSO — same value for all pins in a DeSO
- [ ] Proximity factors (prox_grocery etc.) are computed per pin — different values for different addresses
- [ ] No double-counting in the composite score (area 70% + proximity 30%)

### Per-School Data
- [ ] Report includes individual schools within radius, not DeSO averages
- [ ] Schools from neighboring DeSOs appear if within radius
- [ ] Schools with no meritvärde data show "Inga publicerade meritvärden"
- [ ] "No schools within 2km" case shows nearest school with distance

### Weights
- [ ] Total active weights = 1.0
- [ ] Per-category totals match the table above (±0.01)
- [ ] Score recomputation passes sanity checks

---

## What NOT to Do

- **DO NOT delete the DeSO-level school indicators.** They're valid area-level metrics. Add per-school proximity ON TOP, don't replace.
- **DO NOT expose `foreign_background_pct` in any user-facing category.** It stays `contextual` with weight 0. Used internally for the disaggregation model, never shown to users.
- **DO NOT count contextual indicators in user-facing numbers.** "30 indikatorer" not "33". The user doesn't care about population count.
- **DO NOT create a "Social" or "Demographics" display category.** Mixing foreign background + debt + income under a social label is legally and ethically fraught in Sweden. Economy handles the financial indicators. Demographics stays internal.
- **DO NOT rename `category` column values in a way that breaks existing code.** Check all references to old category names (income, employment, financial_distress, amenities, transport, demographics, housing, crime) before running the migration.