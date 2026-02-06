# TASK: Skolverket School Data + Sidebar Redesign + School Markers

## Context

We have SCB demographics flowing through the indicator pipeline and coloring the map. Now we add the second data source: **Skolverket school data**. This is point data (each school has coordinates), which is a different ingestion pattern than SCB's DeSO-level data and validates that the indicator architecture generalizes.

This task also fixes the UI: the current drawer overlays the map, which makes it impossible to browse the map while reading the data. We replace it with a persistent sidebar.

## Goals

1. Redesign the UI: replace the drawer/sheet with a fixed sidebar so map + data are visible simultaneously
2. Ingest school data from Skolverket APIs (school registry + planned educations with statistics)
3. Aggregate school quality metrics to DeSO level as new indicators
4. Show school markers on the map ONLY for the currently selected DeSO
5. Display individual school details in the sidebar when a DeSO is selected

---

## Step 1: UI Redesign — Persistent Sidebar

### 1.1 Layout Change

Replace the current full-screen map + drawer overlay with a **two-panel layout**:

```
┌──────────────────────────────────────────────────────────────┐
│  Navbar                                                       │
├────────────────────────────────────┬─────────────────────────┤
│                                    │                         │
│                                    │   Sidebar (400px)       │
│          Map                       │                         │
│       (flex: 1)                    │   - DeSO info           │
│                                    │   - Score breakdown     │
│                                    │   - School list         │
│                                    │   - Indicator bars      │
│                                    │                         │
├────────────────────────────────────┴─────────────────────────┤
```

**Specifications:**

- Sidebar is a **fixed-width panel** on the right (400px on desktop, full-width overlay on mobile)
- Map takes the remaining width (`flex: 1` or `calc(100vw - 400px)`)
- Sidebar is **always visible** — it shows a default state when no DeSO is selected ("Click a DeSO area to view details")
- When a DeSO is selected, the sidebar populates with data
- The sidebar should be **scrollable** independently of the map (use shadcn `ScrollArea`)
- The map must **resize properly** when the sidebar appears/hides — call `map.updateSize()` on OpenLayers after layout changes
- On mobile (<768px), the sidebar becomes a bottom sheet that takes 40% of the screen height, map takes the top 60%

### 1.2 Remove the Drawer/Sheet

Delete the current shadcn `Sheet` component that slides over the map. Replace all its content with the sidebar panel.

### 1.3 Sidebar Content Structure

When a DeSO is selected, the sidebar shows these sections in order:

**Header:**
- DeSO code + name (if available)
- Kommun name, Län name
- Area (km²)

**Score Section:**
- Large composite score number (colored by the score gradient)
- Trend badge: ↑ +3.2 or ↓ -1.8 or → 0.0
- Score label: "Strong Growth Area" / "Mixed Signals" / etc.

**Indicator Breakdown:**
- Each active indicator as a horizontal bar:
  ```
  Median Income          ████████░░  78th (287,000 SEK)
  Employment Rate        ██████░░░░  61st (72.3%)
  School Quality         █████████░  91st (242 meritvärde)
  ```
- Bar color matches direction: green for positive contribution, purple for negative
- Show raw value in parentheses

**Schools Section (NEW — from this task):**
- Header: "Schools in this area" with count
- List of schools within the DeSO, each showing:
  - School name
  - Type (grundskola/gymnasie/etc.)
  - Key stat (meritvärde or goal achievement %)
  - Main operator type badge (kommunal/fristående)
- If no schools in this DeSO, show "No schools in this area. Nearest school: [name] ([distance])"

**Top Factors:**
- Green badges for strengths
- Purple badges for weaknesses

---

## Step 2: Database — Schools Table

### 2.1 Migration

```php
Schema::create('schools', function (Blueprint $table) {
    $table->id();
    $table->string('school_unit_code', 20)->unique()->index();  // Skolverket's unique ID
    $table->string('name');
    $table->string('municipality_code', 4)->nullable()->index();
    $table->string('municipality_name')->nullable();
    $table->string('type_of_schooling')->nullable();     // "Grundskola", "Gymnasieskola", etc.
    $table->string('operator_type')->nullable();          // "Kommunal", "Fristående", "Statlig"
    $table->string('operator_name')->nullable();
    $table->string('status')->default('active');          // "active", "inactive"
    $table->decimal('lat', 10, 7)->nullable();
    $table->decimal('lng', 10, 7)->nullable();
    $table->string('deso_code', 10)->nullable()->index(); // Resolved DeSO via PostGIS point-in-polygon
    $table->string('address')->nullable();
    $table->string('postal_code', 10)->nullable();
    $table->string('city')->nullable();
    $table->timestamps();
});

// Spatial index on coordinates
DB::statement("SELECT AddGeometryColumn('public', 'schools', 'geom', 4326, 'POINT', 2)");
DB::statement("CREATE INDEX schools_geom_idx ON schools USING GIST (geom)");
```

### 2.2 School Statistics Table

Store per-school statistics over time. Separate from the main schools table because stats update annually while school info updates daily.

```php
Schema::create('school_statistics', function (Blueprint $table) {
    $table->id();
    $table->string('school_unit_code', 20)->index();
    $table->string('academic_year', 10);               // "2023/24", "2024/25"
    $table->decimal('merit_value_17', 6, 1)->nullable();  // Genomsnittligt meritvärde (17 ämnen)
    $table->decimal('merit_value_16', 6, 1)->nullable();  // Meritvärde (16 ämnen)
    $table->decimal('goal_achievement_pct', 5, 1)->nullable();  // % students achieving goals in all subjects
    $table->decimal('eligibility_pct', 5, 1)->nullable();       // % eligible for gymnasie national programs
    $table->decimal('teacher_certification_pct', 5, 1)->nullable(); // % certified teachers (behöriga lärare)
    $table->integer('student_count')->nullable();
    $table->string('data_source')->nullable();
    $table->timestamps();

    $table->unique(['school_unit_code', 'academic_year']);
});
```

---

## Step 3: Skolverket Data Ingestion

Skolverket has **two relevant APIs**. We need both:

### 3.1 API 1: Skolenhetsregistret (School Registry)

This gives us the school's identity, location, and metadata. Updated daily.

**API v2 (current):**
- Swagger: https://api.skolverket.se/skolenhetsregistret/swagger-ui/index.html
- Base URL: `https://api.skolverket.se/skolenhetsregistret/v2`
- Key endpoint: `GET /v2/school-units` — paginated list of all school units
- Per school: `GET /v2/school-units/{schoolUnitCode}`

**Key fields per school unit:**
- `schoolUnitCode` — unique identifier (e.g., "67890123")
- `schoolUnitName` — school name
- `municipalityCode` — kommun code
- `typeOfSchooling` — school type(s)
- `principalOrganizerType` — kommunal, fristående, statlig
- `wgs84Latitude`, `wgs84Longitude` — coordinates (request with coordinate param)
- `status` — active/inactive

**Pagination:** The API paginates. Iterate through all pages to get all ~10,000+ school units. Use `?page=0&size=100` and follow pagination until done.

### 3.2 API 2: Planned Educations (Statistics)

This gives us the actual performance statistics per school unit.

**API v3:**
- Swagger: https://api.skolverket.se/planned-educations/swagger-ui/index.html
- Base URL: `https://api.skolverket.se/planned-educations/v3`
- Key endpoint: `GET /v3/school-units/{schoolUnitCode}` — detailed school info with statistics
- Accept header: `application/vnd.skolverket.plannededucations.api.v3.hal+json`

**Statistics fields to look for in the response:**
- Genomsnittligt meritvärde (average merit value) — the primary quality metric
- Andel behöriga till gymnasiet (% eligible for gymnasie)
- Andel som nått kunskapskraven i alla ämnen (% achieving goals in all subjects)
- Teacher statistics (andel behöriga lärare)

**Important:** The statistics may be nested in the response under a `statistics` or `educationStatistics` object. The agent needs to explore the Swagger docs and a few sample responses to map the exact field paths. Statistics are per academic year.

**Note:** In September 2020, Skolverket was forced to unpublish school-level statistics due to a government decision. Since then, per-school statistics have been partially re-published through the Planned Educations API with some restrictions. If per-school meritvärde is not available from the API, fall back to:
1. Municipal-level statistics from the Skolverket statistics database (https://www.skolverket.se/skolutveckling/statistik)
2. Excel download from Skolverket's statistics page (betyg per skola)
3. The SALSA model data which is published per school

The agent should first try the API and only fall back if specific statistics aren't available.

### 3.3 Alternative: Excel Download

Skolverket publishes a daily Excel extract of the school registry:
https://www.skolverket.se/skolutveckling/skolenhetsregistret

And annual statistics downloads:
https://www.skolverket.se/skolutveckling/statistik

If the API is difficult, the Excel fallback works fine for an initial implementation.

### 3.4 Artisan Commands

Create two commands:

```bash
php artisan ingest:skolverket-schools    # Registry data: school locations + metadata
php artisan ingest:skolverket-stats      # Performance statistics per school
```

**Command 1: `ingest:skolverket-schools`**

1. Paginate through the Skolenhetsregistret API v2 (`/v2/school-units`)
2. For each school unit:
   - Extract code, name, coordinates, municipality, type, operator type
   - If coordinates are present, create a PostGIS point: `ST_SetSRID(ST_MakePoint(lng, lat), 4326)`
3. Upsert into `schools` table
4. **After all schools are imported:** resolve DeSO codes via spatial join:

```sql
UPDATE schools s
SET deso_code = d.deso_code
FROM deso_areas d
WHERE ST_Contains(d.geom, s.geom)
  AND s.geom IS NOT NULL
  AND s.deso_code IS NULL;
```

This is the point-in-polygon operation that assigns each school to its DeSO. It should take seconds for ~10,000 schools against 6,160 DeSOs with spatial indexes.

5. Log how many schools were imported and how many got DeSO assignments

**Command 2: `ingest:skolverket-stats`**

1. For each active school with a `school_unit_code` in our database:
   - Fetch statistics from the Planned Educations API v3
   - Parse the meritvärde, goal achievement, eligibility, teacher certification
2. Upsert into `school_statistics` table
3. **After stats are loaded:** aggregate to DeSO-level indicators (Step 4)

**Rate limiting:** Be respectful to Skolverket's APIs. Add a small delay between requests (100-200ms). The school registry fetch should take 2-5 minutes for all schools.

### 3.5 SkolverketApiService

Create `app/Services/SkolverketApiService.php` that handles:
- Paginated fetches from the school registry v2
- Per-school statistics from planned educations v3
- Rate limiting (configurable delay between requests)
- Response parsing and field extraction
- Error handling for schools that return 404 or have missing data

---

## Step 4: Aggregate School Quality to DeSO Indicators

### 4.1 New Indicators

Add these to the `indicators` table (via seeder or migration):

| slug | name | unit | direction | weight | category |
|---|---|---|---|---|---|
| `school_merit_value_avg` | Average Merit Value (Schools) | points | positive | 0.12 | education |
| `school_goal_achievement_avg` | Goal Achievement Rate (Schools) | percent | positive | 0.08 | education |
| `school_teacher_certification_avg` | Teacher Certification Rate | percent | positive | 0.05 | education |

**These are DeSO-level aggregates**, not per-school values. The raw_value for each DeSO is the average across all schools physically located within that DeSO.

### 4.2 Aggregation Logic

Create `app/Console/Commands/AggregateSchoolIndicators.php`:

```bash
php artisan aggregate:school-indicators [--academic-year=2023/24]
```

For each DeSO that contains at least one school:

1. Find all schools in this DeSO (via `schools.deso_code`)
2. Get their latest statistics from `school_statistics`
3. Compute the average merit value, average goal achievement, average teacher certification
4. **Weight by student count** if available (a school with 500 students should matter more than one with 50)
5. Store as `indicator_values` rows for the corresponding indicator slugs

**For DeSOs with no schools:**
Leave the indicator_values rows as NULL. The normalization service already handles NULLs (they get excluded from ranking). The frontend shows "No schools in this area" in the sidebar.

**Mapping academic year to calendar year:**
Academic year "2023/24" maps to calendar year 2024 (the year the final grades are given). Use the calendar year for the `indicator_values.year` column to stay consistent with SCB data.

### 4.3 Update Weights

After adding school indicators, the total active weight budget changes. Suggested rebalancing:

| Category | Previous | New |
|---|---|---|
| Income (SCB) | 0.25 | 0.20 |
| Employment (SCB) | 0.10 | 0.10 |
| Education – demographics (SCB) | 0.15 | 0.10 |
| Education – school quality (Skolverket) | 0.00 | 0.25 |
| Unallocated (crime, debt, POI) | 0.50 | 0.35 |

School quality getting 0.25 is intentional — it's the single biggest driver of real estate prices in Sweden. Parents will pay 500,000+ SEK more for an apartment in a DeSO with a school averaging meritvärde 250 versus one averaging 190.

The admin dashboard should reflect these updated weights. Don't hardcode — update via the seeder or a migration that modifies the `indicators` table.

### 4.4 Recompute Scores

After aggregation, run:

```bash
php artisan normalize:indicators --year=2024
php artisan compute:scores --year=2024
```

The composite scores now incorporate school quality. The map colors should shift.

---

## Step 5: School Markers on Map

### 5.1 School API Endpoint

Create an endpoint that returns schools for a specific DeSO:

```php
Route::get('/api/deso/{desoCode}/schools', [DesoController::class, 'schools']);
```

```php
public function schools(string $desoCode)
{
    $schools = School::where('deso_code', $desoCode)
        ->where('status', 'active')
        ->with(['latestStatistics']) // eager load
        ->get()
        ->map(fn ($school) => [
            'school_unit_code' => $school->school_unit_code,
            'name' => $school->name,
            'type' => $school->type_of_schooling,
            'operator_type' => $school->operator_type,
            'lat' => $school->lat,
            'lng' => $school->lng,
            'merit_value' => $school->latestStatistics?->merit_value_17,
            'goal_achievement' => $school->latestStatistics?->goal_achievement_pct,
            'teacher_certification' => $school->latestStatistics?->teacher_certification_pct,
            'student_count' => $school->latestStatistics?->student_count,
        ]);

    return response()->json($schools);
}
```

### 5.2 Map Layer Behavior

**Critical: School markers should ONLY appear for the currently selected DeSO.**

When the user clicks a DeSO:
1. Highlight the selected DeSO polygon (thicker border, brighter fill)
2. Fetch `/api/deso/{desoCode}/schools`
3. Add school markers as a **separate OpenLayers vector layer** on top of the DeSO layer
4. Each marker is a point feature styled as a small school icon or a colored circle
5. Markers should be **above** the DeSO polygons (higher z-index layer)

When the user clicks a different DeSO:
1. **Clear** the previous school markers
2. Load new schools for the newly selected DeSO
3. Show new markers

When the user clicks empty space (deselects):
1. Clear all school markers
2. Reset the sidebar to the default "Click a DeSO" state

**Marker styling:**
- Circle markers, ~8px radius
- Color by quality: green (meritvärde > 230), yellow (200-230), orange (< 200), gray (no data)
- On hover: show tooltip with school name + meritvärde
- On click: scroll to that school's entry in the sidebar school list and highlight it

### 5.3 Map Interaction Flow

```
User clicks DeSO polygon
  → Sidebar populates with DeSO data + score breakdown + school list
  → School markers appear on map within that DeSO
  → Map optionally zooms/pans to fit the selected DeSO bounds

User hovers over a school marker
  → Tooltip: "Årstaskolan — Meritvärde: 241"

User clicks a school marker
  → Sidebar scrolls to that school in the school list
  → School entry highlights

User clicks a different DeSO
  → Previous markers clear, new markers appear
  → Sidebar updates

User clicks empty map area
  → Markers clear, sidebar resets
```

---

## Step 6: Sidebar School List

### 6.1 School List Component

In the sidebar, after the indicator breakdown, add a "Schools" section:

```tsx
<div className="space-y-3">
  <h3 className="text-sm font-semibold text-muted-foreground uppercase tracking-wide">
    Schools in this area ({schools.length})
  </h3>
  {schools.map(school => (
    <SchoolCard key={school.school_unit_code} school={school} />
  ))}
</div>
```

### 6.2 SchoolCard Component

Each school card shows:

```
┌─────────────────────────────────────┐
│ 🏫 Årstaskolan                      │
│ Grundskola · Kommunal               │
│                                     │
│ Meritvärde    ████████░░  241       │
│ Goal ach.     █████████░  94%       │
│ Teachers      ███████░░░  78%       │
│ Students      342                   │
└─────────────────────────────────────┘
```

Use shadcn `Card` component. The merit value bar should use the same color logic as the map markers (green/yellow/orange).

### 6.3 No Schools State

If the DeSO has no schools:

```
┌─────────────────────────────────────┐
│ Schools in this area (0)            │
│                                     │
│ No schools are located in this      │
│ DeSO. The school quality score is   │
│ based on the nearest school(s)      │
│ within neighboring areas.           │
│                                     │
│ Nearest: Årstaskolan (0.4 km)       │
└─────────────────────────────────────┘
```

The nearest school lookup can be done with a PostGIS query:

```sql
SELECT s.name, ST_Distance(
    s.geom::geography,
    ST_Centroid(d.geom)::geography
) / 1000 as distance_km
FROM schools s, deso_areas d
WHERE d.deso_code = ?
  AND s.geom IS NOT NULL
ORDER BY s.geom <-> ST_Centroid(d.geom)
LIMIT 3;
```

---

## Step 7: Full Pipeline Test

### 7.1 Run Everything

```bash
# 1. Ingest school locations
php artisan ingest:skolverket-schools

# 2. Ingest school statistics
php artisan ingest:skolverket-stats

# 3. Aggregate to DeSO-level indicators
php artisan aggregate:school-indicators --academic-year=2024/25

# 4. Normalize all indicators (including new school ones)
php artisan normalize:indicators --year=2024

# 5. Recompute composite scores
php artisan compute:scores --year=2024
```

### 7.2 Database Verification

```sql
-- Check school import
SELECT COUNT(*) FROM schools;  -- Expect ~10,000-12,000
SELECT COUNT(*) FROM schools WHERE deso_code IS NOT NULL;  -- Most should have DeSO assignment
SELECT COUNT(*) FROM schools WHERE lat IS NOT NULL;  -- Most should have coordinates

-- Check DeSO distribution
SELECT COUNT(DISTINCT deso_code) FROM schools WHERE deso_code IS NOT NULL;
-- Not all 6,160 DeSOs will have schools — many rural DeSOs won't

-- Check statistics
SELECT COUNT(*) FROM school_statistics;
SELECT academic_year, COUNT(*), AVG(merit_value_17), MIN(merit_value_17), MAX(merit_value_17)
FROM school_statistics
GROUP BY academic_year;

-- Check DeSO-level school indicators
SELECT i.slug, COUNT(iv.id), AVG(iv.raw_value)
FROM indicator_values iv
JOIN indicators i ON i.id = iv.indicator_id
WHERE i.source = 'skolverket'
GROUP BY i.slug;

-- Sanity: DeSOs with highest school quality should be wealthy areas
SELECT iv.deso_code, da.kommun_name, iv.raw_value as avg_merit
FROM indicator_values iv
JOIN indicators i ON i.id = iv.indicator_id
JOIN deso_areas da ON da.deso_code = iv.deso_code
WHERE i.slug = 'school_merit_value_avg'
ORDER BY iv.raw_value DESC LIMIT 10;
-- Expect: Danderyd, Lidingö, Täby, Nacka, Lomma type areas
```

### 7.3 Visual Checklist

- [ ] **Sidebar is always visible** on the right side of the map (not a drawer/overlay)
- [ ] Map and sidebar can be used simultaneously — scrolling the sidebar doesn't affect the map
- [ ] Map resizes correctly when sidebar is present
- [ ] Clicking a DeSO populates the sidebar with all sections (score, indicators, schools)
- [ ] School markers appear ONLY for the selected DeSO
- [ ] School markers are colored by quality (green/yellow/orange)
- [ ] Hovering a school marker shows tooltip with name + meritvärde
- [ ] Clicking a school marker scrolls to it in the sidebar
- [ ] Clicking a different DeSO clears previous markers and shows new ones
- [ ] Clicking empty space clears markers and resets sidebar
- [ ] DeSOs with no schools show "No schools" message with nearest school
- [ ] Map colors have shifted slightly compared to before (school quality now affects composite score)
- [ ] Admin page shows new school quality indicators with weights
- [ ] Changing school quality weight and recomputing changes the map

---

## Notes for the Agent

### Two Skolverket APIs, Different Purposes

| API | What it gives you | Base URL |
|---|---|---|
| Skolenhetsregistret v2 | School identity, location, type, operator | `https://api.skolverket.se/skolenhetsregistret/v2` |
| Planned Educations v3 | Statistics (meritvärde, achievement, teachers) | `https://api.skolverket.se/planned-educations/v3` |

You need both. Fetch the school list from the registry, then enrich with statistics from planned educations. They share the `schoolUnitCode` as the join key.

### Swagger Docs

Explore these interactively to understand the exact response shapes:
- https://api.skolverket.se/skolenhetsregistret/swagger-ui/index.html
- https://api.skolverket.se/planned-educations/swagger-ui/index.html

### Accept Headers Matter

The Planned Educations API requires a specific Accept header:
```
Accept: application/vnd.skolverket.plannededucations.api.v3.hal+json
```

Without it, you may get 406 Not Acceptable.

### School Types to Focus On

For our real estate use case, **grundskola (primary/lower secondary, years F-9)** is the most relevant. Parents choosing where to live care most about the local grundskola. Gymnasieskola matters less because students travel across municipalities.

Filter for `typeOfSchooling` containing "Grundskola" when computing DeSO-level quality indicators. Store all school types in the database, but only aggregate grundskola to the quality indicators.

### The Meritvärde Metric

Meritvärde (merit value) is the primary quality metric. It's computed from final grades in year 9:
- Sum of the 16 best subject grades (A=20, B=17.5, C=15, D=12.5, E=10, F=0)
- Plus the grade in moderna språk (modern languages) as a 17th subject if taken
- Maximum possible: 340 (17 × 20)
- National average 2025: ~228.5 (17 subjects)
- Top schools: 270-290+
- Struggling schools: 150-180

### Point-in-Polygon for DeSO Assignment

After importing schools with coordinates, resolve their DeSO using PostGIS `ST_Contains`. This is a one-time spatial join — run it after school import. Some schools may fall outside DeSO boundaries (offshore schools, data errors). Log these but don't crash.

### What NOT to do

- Don't show ALL schools on the map at once — only the selected DeSO's schools
- Don't use the old Sheet/drawer component — replace it fully with the sidebar
- Don't aggregate gymnasie/komvux statistics into DeSO quality scores — only grundskola
- Don't block on missing statistics — many schools may not have meritvärde data. Store what's available, leave NULLs for the rest
- Don't fetch statistics one-by-one if a batch endpoint exists — check the Swagger first

### What to prioritize

1. Get the sidebar UI working first (before touching Skolverket APIs)
2. Ingest school locations with coordinates and DeSO assignment
3. Get school markers appearing for selected DeSOs
4. Then add statistics and aggregate to indicators
5. The sidebar interaction (click marker → scroll to school) is polish but makes the product feel great

### Update CLAUDE.md

Add any Skolverket API quirks, field mapping discoveries, or DeSO assignment edge cases to the best practices section.