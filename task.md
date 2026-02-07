# TASK: School Data Audit & Re-ingestion

## Context

We have ~7,000 schools in the database. The real number is **~10,600+** school units in Sweden. We're missing roughly 3,000–4,000 schools, which means our school quality indicators are computed from incomplete data — entire DeSOs that should show school quality scores are showing "no schools" because the schools in their area were never ingested.

This task audits what went wrong, fixes the ingestion, and addresses the related statistics availability problem.

## The Real Numbers

According to Skolverket's geodata portal, the actual school unit counts by school form are:

| School form | Count |
|---|---|
| Förskoleklass (preschool class) | 3,618 |
| **Grundskola** (compulsory school) | **4,748** |
| Sameskola (Sami school) | 5 |
| Specialskola (special needs school) | 10 |
| **Gymnasieskola** (upper secondary) | **1,313** |
| Anpassad grundskola (adapted compulsory) | 667 |
| Anpassad gymnasieskola (adapted upper secondary) | 251 |
| **Total** | **~10,600+** |

Additionally there are Komvux (adult education), folkhögskola, and other education providers in the registry.

Our 7,000 is **not plausible** for a complete fetch. We're getting roughly 65% of what's in the registry.

## Probable Causes of Missing Schools

### Cause 1: API v1 Deprecation / Pagination Failure

The original ingestion was built on Skolenhetsregistret API v1. Skolverket moved to v2 as the active version on 2024-12-13, and v1 was planned for deprecation at the end of 2025/2026. The API pagination may have stopped returning complete results, the API may have become intermittently unreliable, or the page size / offset handling may have been incorrect.

**API v2 is now the recommended version.** The ingestion must be migrated to v2.

### Cause 2: Overly Aggressive Filtering

The original task said to focus on grundskola for the quality indicators, which is correct for *scoring*. But the command may have been built to only *fetch* grundskola, skipping gymnasieskola and other school forms entirely. We should **store all school forms** in the database (for display on the map as markers, for showing in the sidebar, for completeness) and then **filter to grundskola only** when computing DeSO-level quality indicators.

Parents care about grundskola for the score, but when browsing a DeSO, seeing *all* schools (including the local gymnasium) gives a more complete picture of the area.

### Cause 3: Schools Without Coordinates Silently Dropped

If the ingestion skipped schools that had no lat/lng in the API response, many schools may have been lost. Some schools — especially newer ones, schools in temporary locations, or komvux providers — may not have coordinates in the registry. These should still be stored (with null coordinates) and can potentially be geocoded from their address.

### Cause 4: Inactive/Ceased Schools Excluded

The v2 API now explicitly exposes ceased (upphörda) school units. The original v1 fetch may have been implicitly filtering to active only. We should store both active and inactive schools (with a status field) but only display active ones on the map.

---

## Step 1: Audit Current State

Before re-ingesting, understand what we have:

```bash
php artisan tinker
```

```php
// Total schools
\App\Models\School::count();  // ~7000

// By school type
\App\Models\School::select('type_of_schooling', DB::raw('count(*)'))
    ->groupBy('type_of_schooling')
    ->orderByDesc(DB::raw('count(*)'))
    ->get();

// How many have coordinates?
\App\Models\School::whereNotNull('lat')->count();

// How many have DeSO assignment?
\App\Models\School::whereNotNull('deso_code')->count();

// How many have statistics?
\App\Models\SchoolStatistic::distinct('school_unit_code')->count();

// Which municipality has the most schools?
\App\Models\School::select('municipality_name', DB::raw('count(*)'))
    ->groupBy('municipality_name')
    ->orderByDesc(DB::raw('count(*)'))
    ->limit(10)
    ->get();
```

Log the results. Compare against the known totals above. Determine which school forms are missing and how many schools lack coordinates.

---

## Step 2: Migrate to Skolenhetsregistret API v2

### 2.1 API v2 Changes

Key differences from v1 (from Skolverket's changelog):

- Adapted to recommended REST API profile for public actors
- Service and parameter names translated to English
- Can combine search parameters when searching school units and principals
- **Ceased school units are now accessible** (new feature)
- Services for listing municipalities and school forms removed
- `harbetygsratt` replaced with search parameter `?grading_rights=true`
- If a variable is null, it's not included in the response (both v1 and v2)

### 2.2 API v2 Swagger

Explore the v2 Swagger docs:
`https://api.skolverket.se/skolenhetsregistret/swagger-ui/index.html`

The agent should fetch the swagger and map the exact endpoint paths, pagination parameters, and response shapes for v2.

### 2.3 Update SkolverketApiService

Rewrite `app/Services/SkolverketApiService.php` to use v2:

```php
class SkolverketApiService
{
    private string $registryBaseUrl = 'https://api.skolverket.se/skolenhetsregistret/v2';
    private string $statsBaseUrl = 'https://api.skolverket.se/planned-educations/v3';

    /**
     * Fetch ALL school units from the registry, paginating through all pages.
     * Returns a generator to avoid loading 10,000+ schools into memory at once.
     */
    public function fetchAllSchoolUnits(): \Generator
    {
        $page = 0;
        $size = 100;  // Or whatever v2's max page size is

        do {
            $response = Http::acceptJson()
                ->get("{$this->registryBaseUrl}/school-units", [
                    'page' => $page,
                    'size' => $size,
                ]);

            if (!$response->successful()) {
                Log::error("Skolverket API error on page {$page}", [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                break;
            }

            $data = $response->json();
            $schoolUnits = $data['body'] ?? $data['_embedded']['school-units'] ?? $data;

            // The exact response structure depends on v2 — agent must check Swagger
            if (empty($schoolUnits) || !is_array($schoolUnits)) {
                break;
            }

            foreach ($schoolUnits as $unit) {
                yield $unit;
            }

            $page++;

            // Rate limiting: be polite
            usleep(150_000); // 150ms between requests

            // Detect last page (check total pages from response metadata)
            $totalPages = $data['totalPages'] ?? null;
            $hasMore = $totalPages ? ($page < $totalPages) : (count($schoolUnits) === $size);

        } while ($hasMore);
    }
}
```

### 2.4 Key Fields to Extract per School Unit

Map v2 field names (which are now in English) to our database columns:

| v2 field (expected) | Our column |
|---|---|
| `schoolUnitCode` | `school_unit_code` |
| `schoolUnitName` | `name` |
| `municipalityCode` | `municipality_code` |
| `municipalityName` | `municipality_name` |
| `typeOfSchooling` | `type_of_schooling` |
| `principalOrganizerType` | `operator_type` |
| `principalOrganizerName` | `operator_name` |
| `status` | `status` |
| `wgs84Latitude` or equivalent | `lat` |
| `wgs84Longitude` or equivalent | `lng` |
| `postalAddress.street` | `address` |
| `postalAddress.postalCode` | `postal_code` |
| `postalAddress.city` | `city` |

**Important:** The exact v2 field names may differ from v1. The agent MUST check the Swagger docs and/or make a sample API call to verify field names before writing the mapping code.

---

## Step 3: Re-ingest All Schools

### 3.1 Updated Command

```bash
php artisan ingest:skolverket-schools --force
```

The `--force` flag means: clear and re-import everything, not just upsert new ones. For a one-time re-ingestion to fix the data gap, this is cleaner than trying to incrementally patch.

The command should:

1. Fetch ALL school units from the v2 API (no school form filter — get everything)
2. For each school unit:
   - Parse all fields including coordinates and address
   - Store type_of_schooling as-is (may be a list: a school unit can offer both grundskola and förskoleklass)
   - If coordinates are present, create PostGIS point geometry
   - Store with status (active/ceased)
3. After import: run PostGIS spatial join to assign DeSO codes
4. For schools without coordinates but with address: attempt geocoding (Step 4)
5. Log comprehensive stats:

```
Total school units in API: 10,612
  - Active: 10,198
  - Ceased: 414
  - With coordinates: 9,847
  - Without coordinates: 765
  - DeSO assigned: 9,721
School forms breakdown:
  - Grundskola: 4,748
  - Gymnasieskola: 1,313
  - Förskoleklass: 3,618
  - Anpassad grundskola: 667
  - Anpassad gymnasieskola: 251
  - Specialskola: 10
  - Sameskola: 5
```

### 3.2 Handle Multiple School Forms per Unit

A single school unit code can offer multiple school forms (e.g., both grundskola and förskoleklass, or grundskola + anpassad grundskola). The `type_of_schooling` field may be a comma-separated list or an array.

Store it as-is in a string field, but also add a JSON column for structured access:

**Migration update:**

```php
$table->json('school_forms')->nullable();  // ["Grundskola", "Förskoleklass"]
```

When filtering for grundskola during indicator aggregation, use:

```php
School::whereJsonContains('school_forms', 'Grundskola')
```

Or if stored as a string:

```php
School::where('type_of_schooling', 'like', '%Grundskola%')
```

---

## Step 4: Geocoding Schools Without Coordinates

### 4.1 The Problem

Some schools in the registry have a street address but no lat/lng. These are still real schools that serve students in a DeSO — we shouldn't silently ignore them.

### 4.2 Geocoding Strategy

For schools with address + postal code + city but no coordinates:

**Option A: Nominatim (free, OSM-based)**

```php
$response = Http::get('https://nominatim.openstreetmap.org/search', [
    'q' => "{$school->address}, {$school->postal_code} {$school->city}, Sweden",
    'format' => 'json',
    'limit' => 1,
    'countrycodes' => 'se',
]);
```

Rate limit: 1 request per second. For ~700 schools, that's ~12 minutes.

**Option B: Skip for now**

If geocoding is too complex for this task, store the schools without coordinates and note them. They won't appear on the map and won't get DeSO assignments, but they'll be in the database for future geocoding.

**Recommendation:** Use Nominatim. It's free, it's accurate enough for Sweden, and recovering ~700 schools from address data is worth 12 minutes of API calls.

### 4.3 Geocoding Command

```bash
php artisan geocode:schools --source=nominatim
```

Runs only on schools where `lat IS NULL AND address IS NOT NULL`. Updates lat, lng, and geom. Then re-runs the DeSO spatial join for newly geocoded schools.

---

## Step 5: Statistics Availability Problem

### 5.1 The Sekretess Saga

In September 2020, Skolverket was forced to stop publishing per-school statistics due to a court ruling that friskola (independent school) statistics are business secrets under Swedish secrecy law. This affected betyg, elevsammansättning, and behöriga lärare statistics.

The situation has partially resolved:
- **Pre-September 2020 statistics** were re-published (old data is available)
- **New statistics** are being published again through Skolverket's search interface and the Planned Educations API, but with some restrictions
- A temporary law allows Skolverket to share school-level data, but the permanent legal solution is still being worked out

### 5.2 What This Means for Us

The Planned Educations API v3 *should* have per-school statistics, but:
- Not all schools may have stats (especially newer friskolor)
- Some statistics may be suppressed if publishing them would identify a specific school with fewer than a threshold number of students
- The API response structure for statistics may be complex

### 5.3 Alternative/Supplementary Statistics Sources

If the Planned Educations API doesn't give us sufficient coverage, these alternatives exist:

**Source 1: Skolverket's "Sök statistik" Excel Downloads**

Skolverket's statistics search page lets you download per-school data as Excel files:
`https://www.skolverket.se/skolutveckling/statistik/sok-statistik-om-forskola-skola-och-vuxenutbildning`

Download the bulk Excel file for grundskola with betyg/meritvärde. This may have broader coverage than the API for per-school grade data.

**Source 2: Skolverket's "Välja skola" / Utbildningsguiden**

Skolverket's school choice guide publishes per-school data for parents:
- Grades (meritvärde)
- Behöriga lärare percentage
- National test results
- Andel som nått kunskapskraven

The underlying data is what we want. Check if it's available through an API or download.

**Source 3: Kolada (Municipality Comparison Database)**

SKR (Sveriges Kommuner och Regioner) publishes school statistics per municipality in Kolada:
`https://www.kolada.se/`

This is aggregated to municipality level (not per-school), but provides a fallback for municipalities where per-school data is restricted.

**Source 4: J++ Skolstatistik Archive**

Data journalists at J++ Stockholm scraped and archived Skolverket statistics before the 2020 blackout:
`https://github.com/jplusplus/skolstatistik`

Historical per-school data is available in their S3 archive. Useful for historical trends but should be used as supplementary data, not primary.

### 5.4 Multi-Source Statistics Strategy

1. **Primary:** Try Planned Educations API v3 for each school
2. **Fallback 1:** Download Skolverket's bulk Excel statistics files for grundskola betyg
3. **Fallback 2:** Use Kolada for municipal-level averages where per-school data is unavailable
4. **Never:** Don't fabricate per-school stats from municipal averages — if we don't have per-school data, store NULL

---

## Step 6: Rebuild Statistics Ingestion

### 6.1 Updated Stats Command

```bash
php artisan ingest:skolverket-stats [--academic-year=2023/24] [--fallback-excel]
```

The command should:

1. **Primary path:** For each active grundskola in our database, try the Planned Educations API v3
   - Fetch `GET /v3/school-units/{schoolUnitCode}`
   - Parse meritvärde, goal achievement, teacher certification from the response
   - The agent MUST explore the Swagger docs to find the exact field paths — these are nested and may differ from expectations
   - Accept header: `application/vnd.skolverket.plannededucations.api.v3.hal+json`

2. **Fallback path:** If the API returns no statistics for many schools (>50% missing), download the bulk Excel file from Skolverket's statistics page and parse it with PhpSpreadsheet

3. **Store all available stats** in `school_statistics` table

4. **Log coverage:**

```
Statistics ingestion for 2023/24:
  Grundskolor attempted: 4,748
  Stats from API: 3,200
  Stats from Excel fallback: 800
  No stats available: 748
  
  Merit value: 3,100 schools (avg: 228.4, min: 148, max: 302)
  Goal achievement: 3,400 schools
  Teacher certification: 3,800 schools
```

### 6.2 Handle the "Dots" Problem

Skolverket represents suppressed data as "." (a dot) in their statistics. This means the value exists but is hidden to protect student privacy (typically when fewer than 10 students are in a cohort). Store these as NULL in our database, not as 0. The distinction matters: 0 merit value means "all students failed" (which never happens), NULL means "data suppressed or unavailable."

---

## Step 7: Re-aggregate School Quality Indicators

After clean data is loaded:

```bash
# 1. Re-aggregate school quality to DeSO level
php artisan aggregate:school-indicators --academic-year=2023/24

# 2. Re-normalize all indicators (school quality percentiles will shift)
php artisan normalize:indicators --year=2024

# 3. Recompute composite scores (school quality now based on complete data)
php artisan compute:scores --year=2024
```

### 7.1 Expected Impact

With ~4,700 grundskolor instead of ~4,000 (or whatever subset we had before), more DeSOs will have school quality data. The indicators should now cover:
- ~2,000-2,500 DeSOs with at least one grundskola (out of 6,160)
- Remaining DeSOs have no schools and get NULL for school indicators (rural areas, industrial zones, etc.)

The composite scores will shift because the school quality normalization is based on a more complete dataset.

### 7.2 Updated Aggregation: Include Gymnasieskola?

The original task said "only aggregate grundskola." Revisit this:

**Keep grundskola as the primary quality indicator** (parents choosing where to live care most about grundskola). But **add a secondary indicator for gymnasieskola** quality in DeSOs that have one:

| Indicator | School form | Weight |
|---|---|---|
| `school_merit_value_avg` | Grundskola | 0.12 |
| `school_goal_achievement_avg` | Grundskola | 0.08 |
| `school_teacher_certification_avg` | Grundskola | 0.05 |
| `gymnasie_merit_avg` | Gymnasieskola | 0.00 (neutral, display only) |

Gymnasieskola merit value is shown in the sidebar for information but doesn't contribute to the composite score. Students travel across municipalities for gymnasium — its presence in a DeSO doesn't affect real estate prices the way a good grundskola does.

---

## Step 8: Update School Display

### 8.1 School Form Badges in Sidebar

Now that we have all school forms, the sidebar school list should show clear badges:

```
🏫 Årstaskolan                        
Grundskola · F-9 · Kommunal            

🏫 Östra Real
Gymnasieskola · Kommunal               

🏫 Danderyd anpassad grundskola
Anpassad grundskola · Kommunal         
```

Use different badge colors for different school forms:
- Grundskola: default (primary)
- Gymnasieskola: blue badge
- Anpassad grundskola/gymnasieskola: purple badge
- Förskoleklass: gray badge (less important for our use case)

### 8.2 Filter in Sidebar

Add a simple filter toggle in the schools section:

```
Schools in this area (12)
[All] [Grundskola] [Gymnasie] [Other]
```

Default to "All" but let users filter. The count updates based on the filter.

### 8.3 Map Markers

School markers on the map (for selected DeSO) should also reflect school form:
- Grundskola: circle marker colored by quality (green/yellow/orange)
- Gymnasieskola: square or diamond marker colored by quality
- Other: small gray dot

---

## Step 9: Verification

### 9.1 Database Checks

```sql
-- Total schools after re-ingestion
SELECT COUNT(*) FROM schools;
-- Target: 10,000-11,000 (including ceased schools)

-- Active schools
SELECT COUNT(*) FROM schools WHERE status = 'active';
-- Target: ~10,000-10,200

-- Schools by form
SELECT type_of_schooling, COUNT(*)
FROM schools
WHERE status = 'active'
GROUP BY type_of_schooling
ORDER BY COUNT(*) DESC;
-- Verify grundskola ~4,700, gymnasie ~1,300, etc.

-- Schools with coordinates
SELECT COUNT(*) FROM schools WHERE lat IS NOT NULL AND status = 'active';
-- Target: 9,500+ (most should have coords after geocoding)

-- Schools with DeSO assignment
SELECT COUNT(*) FROM schools WHERE deso_code IS NOT NULL AND status = 'active';
-- Target: 9,500+ (same as coordinates — PostGIS assigns from coords)

-- DeSOs with at least one grundskola
SELECT COUNT(DISTINCT deso_code)
FROM schools
WHERE type_of_schooling LIKE '%Grundskola%'
  AND status = 'active'
  AND deso_code IS NOT NULL;
-- Target: 2,000-2,500 DeSOs

-- Statistics coverage
SELECT COUNT(DISTINCT ss.school_unit_code)
FROM school_statistics ss
JOIN schools s ON s.school_unit_code = ss.school_unit_code
WHERE s.type_of_schooling LIKE '%Grundskola%';
-- Target: 3,500+ grundskolor with some statistics

-- Schools in Stockholm kommun (sanity check)
SELECT type_of_schooling, COUNT(*)
FROM schools
WHERE municipality_code = '0180'
  AND status = 'active'
GROUP BY type_of_schooling;
-- Stockholm should have hundreds of schools
```

### 9.2 Compare Before and After

```sql
-- How many DeSOs gained school quality indicators?
-- (Compare indicator_values count for school indicators before and after)
SELECT i.slug, COUNT(iv.id) as deso_count
FROM indicator_values iv
JOIN indicators i ON i.id = iv.indicator_id
WHERE i.source = 'skolverket'
GROUP BY i.slug;

-- Score shift: how much did the top/bottom DeSOs change?
-- (Compare with previous composite_scores if you have a backup)
```

### 9.3 Visual Checklist

- [ ] School count in database matches expected totals (~10,000+ active)
- [ ] All major school forms present (grundskola, gymnasie, förskoleklass, anpassad)
- [ ] Schools visible on map for selected DeSOs — more than before
- [ ] Sidebar school list shows school form badges
- [ ] School filter works in sidebar
- [ ] DeSOs that previously showed "no schools" now show schools (if applicable)
- [ ] Statistics coverage is reasonable (~70-80% of grundskolor have meritvärde data)
- [ ] Composite scores have shifted slightly (expected — more complete data)
- [ ] Stockholm inner city DeSOs show dozens of schools (sanity check)
- [ ] Rural DeSOs correctly show 0-1 schools (not a data gap — genuinely no schools)

---

## Notes for the Agent

### API v2 is Critical

Do not try to fix this by patching the v1 ingestion. v1 is deprecated and will stop working. Migrate to v2 first, then re-ingest. The agent should explore the v2 Swagger docs carefully before writing any code.

### The Excel Download is a Safety Net

Skolverket publishes a daily Excel extract of the full registry:
`https://www.skolverket.se/om-skolverket/webbplatser-och-tjanster/andra-webbplatser-och-tjanster/skolenhetsregistret`

If the API v2 migration is proving difficult, download the Excel file, parse it with PhpSpreadsheet, and import from there. It contains the same data. The API is better for ongoing daily updates, but the Excel file works perfectly for a one-time re-ingestion.

### Statistics Are the Harder Problem

Getting all school *locations* is straightforward (the registry API has them). Getting *statistics* (meritvärde, goal achievement) is harder due to the sekretess situation. The Planned Educations API may not have stats for all schools. The agent should:

1. Try the API first
2. Check coverage (what percentage of grundskolor got stats?)
3. If coverage is low (<60%), download the bulk Excel statistics from Skolverket's stats page
4. If coverage is still low, accept it — some schools genuinely don't have published statistics (small cohorts, new schools, schools that opted out of the system)

### Don't Delete Schools That Exist

When re-ingesting, use upsert (updateOrCreate) on school_unit_code. Don't truncate the table — you'll lose any manual corrections or geocoding results. The `--force` flag should mean "re-fetch and overwrite from API" not "delete everything."

### What to Prioritize

1. **Audit current state** — understand exactly what we have and what's missing
2. **Migrate to API v2** — this is the root fix
3. **Re-ingest all school units** — get to ~10,000+
4. **Geocode missing coordinates** — recover ~700 schools
5. **Re-run DeSO spatial join** — assign newly imported schools to DeSOs
6. **Re-ingest statistics** — this is where it gets messy
7. **Re-aggregate and recompute** — update the scores
8. **UI updates** (badges, filters) — polish, do last

### What NOT to Do

- Don't keep using API v1 — it's deprecated
- Don't only import grundskola — import all forms, filter for scoring
- Don't fabricate statistics for schools that don't have data
- Don't treat "." (dot) values as zero — they're suppressed data, store as NULL
- Don't skip geocoding — 700 schools is significant coverage
- Don't assume the v2 API has the same field names as v1 — check the Swagger