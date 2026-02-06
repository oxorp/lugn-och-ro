# TASK: BRÅ Crime Data + Police Vulnerability Areas + Crime Indicators

## Context

We have SCB demographics and Skolverket school quality flowing through the indicator pipeline and coloring the map. The composite score currently uses income, employment, education demographics, and school quality — all "pull" factors. Now we add the first "push" factor: **crime**.

Crime is the single most emotionally charged variable in Swedish housing decisions. A family will eliminate an area over safety concerns faster than over a 15-point meritvärde difference. Schools are a rational optimization. Crime is a dealbreaker.

**Three data layers, one task:**

1. **BRÅ reported crime statistics** — kommun-level annual data (290 municipalities), downloadable as Excel. This is the structured, official crime rate data.
2. **NTU perceived safety** — localpolisområde-level survey data (94 areas), from BRÅ's National Crime Survey. This captures what people *feel*, which often diverges from what the statistics say.
3. **Police vulnerability classifications** — 65 named neighborhoods with actual GeoJSON/Shapefile boundaries, published by Polisen. Binary signal: is this area on the police's problem list or not?

**The spatial challenge:** None of these sources are at DeSO level. BRÅ has no API — only Excel downloads and a web database (SOL). Crime data is at kommun level (290 areas → 6,160 DeSOs). NTU is at lokalpolisområde level (94 areas → 6,160 DeSOs). The vulnerability areas are named polygons that need spatial intersection with DeSO boundaries.

This is the first data source that requires **spatial disaggregation** — distributing coarse-grained data to fine-grained DeSO areas. The pattern we establish here will be reused for Kronofogden (also kommun-level) and BRÅ police district data.

## Future: Real-Time Crime Events (DO NOT IMPLEMENT NOW)

Later, we want to add a **real-time crime event layer** on top of the statistical indicators. This will track police press releases, news sources, and Polisen's händelser feed to show recent incidents (shootings, bombings, stabbings, robberies) as point markers on the map with appropriate icons. Think of it as a "what happened here recently" overlay — a heatmap of recent activity, not a historical statistic.

**This task does NOT implement the real-time layer.** But the database schema and map architecture must be designed to support it later:

- The `crime_events` table (Step 2.3) is created now but NOT populated. It holds point-level crime events with coordinates, type, timestamp, and severity.
- The map component must support an additional vector layer for point markers (crime event icons) — the school markers layer we already built proves this pattern works.
- The sidebar should have a reserved section for "Recent Incidents" that can be enabled later.
- The crime event types (shooting, bombing, stabbing, robbery, arson) should be defined as an enum/config now so the future ingestion pipeline has a schema to write to.

When we do implement this later, the flow will be:
1. Poll Polisen händelser RSS/API + scrape police press releases + monitor news APIs
2. Geocode each event to coordinates
3. Assign to DeSO via point-in-polygon
4. Show on map as typed markers (🔫 💣 🔪 etc.) with recency fade (bright → faded over 30 days)
5. Aggregate recent event density into a "recent crime activity" indicator that feeds the score

**For now:** schema only. No ingestion, no display, no scraping.

## Goals

1. Ingest BRÅ reported crime statistics from Excel downloads (kommun-level)
2. Disaggregate kommun-level crime rates to DeSO using demographic-weighted regression
3. Import NTU perceived safety data at lokalpolisområde level and map to DeSO
4. Import police vulnerability area polygons and flag affected DeSOs
5. Create crime indicators and integrate into the composite score
6. Show crime data in the sidebar when a DeSO is selected
7. Prepare the database and architecture for future real-time crime events

---

## Step 1: Understand the Data Sources

### 1.1 BRÅ Reported Crime (Anmälda Brott)

**What:** Official statistics on all crimes reported to police, prosecution, and customs. Published by BRÅ as Sweden's official crime statistics authority.

**Granularity:** Kommun + storstädernas stadsområden (stadsdelar for Stockholm, Gothenburg, Malmö). Annual from 1996, monthly/quarterly available but with secrecy restrictions for small areas.

**Format:** Excel download. No public API — BRÅ has explicitly confirmed this.

**Download URLs:**
- Pre-built Excel: `https://bra.se/statistik/statistik-om-rattsvasendet/anmalda-brott` → "Anmälda brott de senaste 10 åren" (130.7 KB xlsx)
- SOL database: `https://statistik.bra.se/solwebb/action/index` → interactive, export to Excel
- Kommun indicators: `https://bra.se/statistik/indikatorer-for-kommuners-lagesbild` → "Indikatorer för anmälda brott" Excel

**Crime categories we care about (real estate relevance):**
- Brott mot person (crimes against persons) — violence, threats, harassment
- Stöld- och tillgreppsbrott (theft) — burglary, car theft, bicycle theft
- Skadegörelsebrott (criminal damage/vandalism)
- Rån (robbery)
- Narkotikabrott (drug offences) — proxy for open drug trade
- Sexualbrott (sexual offences)

**What we DON'T care about for scoring:** Fraud, traffic offences, tax crimes — these don't reflect neighborhood safety.

**Counting quirk:** Sweden counts each individual offence separately. Multiple offences on one occasion = multiple records. Attempted offences counted alongside completed. All reported events recorded even if not criminal after investigation. This inflates raw numbers vs other countries — doesn't matter for our use case since we're comparing Swedish DeSOs to each other.

### 1.2 NTU — National Crime Survey (Nationella Trygghetsundersökningen)

**What:** Annual survey of ~200,000 people aged 16–84 on victimization, perceived safety, fear of crime, and trust in justice system. Published by BRÅ since 2006.

**Granularity:** Down to **lokalpolisområde** (94 local police districts) via BRÅ's interactive tool. Also available by kommun since 2024 (municipal sample expansion). By län since 2017.

**Why it matters:** Perceived safety drives housing decisions as much as actual crime rates. An area can have declining crime but rising fear (media effect). Or low crime but high fear (dark parks, poor lighting). NTU captures what statistics miss.

**Key NTU indicators:**
- Otrygghet kvällstid (feeling unsafe outdoors at night in own area) — THE key metric
- Utsatthet för brott (self-reported victimization)
- Oro för bostadsinbrott (worry about burglary)
- Oro för misshandel (worry about assault)
- Förtroende för polisen (trust in police)

**Data access:**
- Interactive tool: `https://bra.se/statistik/statistik-fran-enkatundersokningar/nationella-trygghetsundersokningen/skapa-din-egen-tabell-ntu` — results down to lokalpolisområde level, exportable
- Excel download: `Tabellsamling NTU 2007-2024` (1.1 MB xlsx) from the NTU publication page
- Län-level: `Resultat för län NTU 2017-2024` (473 KB xlsx)
- By socioeconomic area type per police region: `Resultat för socioekonomiska områdestyper` (380 KB xlsx)
- Kommun indicators: `https://bra.se/statistik/indikatorer-for-kommuners-lagesbild` — NTU indicators per kommun PDF + Excel

### 1.3 Police Vulnerability Areas (Utsatta Områden)

**What:** Polisen's bi-annual classification of neighborhoods with high criminal influence on the local community. 65 areas as of December 2025, of which 19 are "särskilt utsatta" (particularly vulnerable).

**Why it matters extremely:** This is the most powerful binary signal in Swedish real estate. Everyone knows "the list." A DeSO overlapping an utsatt område gets an immediate, significant penalty.

**Key stats (2025 report):**
- 65 areas total (46 utsatta + 19 särskilt utsatta). Riskområde category removed in 2025.
- ~550,000 people (5% of population) live in these areas
- ~60% of all shootings 2022–2024 connected to utsatta områden
- ~5,000 cylinderaktörer (criminal network actors) tied to these areas

**Data:**
- Polygon boundaries: **GeoJSON and Shapefile** directly from Polisen!
  - `https://polisen.se/om-polisen/polisens-arbete/utsatta-omraden/` → "Ladda ner områdesgränserna som geo.json" (44 KB zip) and as shapefile (71 KB zip)
- Report: `Lägesbild över utsatta områden 2025` (PDF, 1 MB)
- Classification list: Published in the report — 65 named areas with tier
- Updated: every 2 years (2015, 2017, 2019, 2021, 2023, 2025)

This is incredibly valuable — actual polygon boundaries for the most stigmatized areas in Sweden, published by the police themselves.

---

## Step 2: Database Migrations

### 2.1 Crime Statistics Table (Kommun-Level Raw Data)

```php
Schema::create('crime_statistics', function (Blueprint $table) {
    $table->id();
    $table->string('municipality_code', 4)->index();
    $table->string('municipality_name')->nullable();
    $table->integer('year')->index();
    $table->string('crime_category', 80)->index();    // 'person', 'theft', 'damage', 'robbery', 'drug', 'sexual', 'total'
    $table->integer('reported_count')->nullable();      // Absolute number of reported crimes
    $table->decimal('rate_per_100k', 10, 2)->nullable(); // Per 100,000 inhabitants
    $table->integer('population')->nullable();           // Municipality population that year
    $table->string('data_source')->nullable();           // 'bra_excel_10yr', 'bra_sol', etc.
    $table->timestamps();

    $table->unique(['municipality_code', 'year', 'crime_category']);
});
```

### 2.2 Vulnerability Areas Table

```php
Schema::create('vulnerability_areas', function (Blueprint $table) {
    $table->id();
    $table->string('name')->index();                    // "Rinkeby", "Rosengård", "Hammarkullen"
    $table->string('tier', 30);                          // 'utsatt', 'sarskilt_utsatt' (riskområde removed 2025)
    $table->string('police_region')->nullable();         // "Stockholm", "Väst", "Syd", etc.
    $table->string('local_police_area')->nullable();     // "LPO Järva", "LPO Södertälje"
    $table->string('municipality_code', 4)->nullable();
    $table->string('municipality_name')->nullable();
    $table->integer('assessment_year');                   // 2025, 2023, 2021, etc.
    $table->boolean('is_current')->default(true);        // Latest assessment
    $table->json('metadata')->nullable();                // Extra info from the report
    $table->timestamps();
});

// Add PostGIS geometry column
DB::statement("SELECT AddGeometryColumn('public', 'vulnerability_areas', 'geom', 4326, 'MULTIPOLYGON', 2)");
DB::statement("CREATE INDEX vulnerability_areas_geom_idx ON vulnerability_areas USING GIST (geom)");
```

### 2.3 Crime Events Table (FUTURE — Create Schema Only)

This table is for the future real-time crime event layer. Create the migration NOW but do NOT populate it.

```php
Schema::create('crime_events', function (Blueprint $table) {
    $table->id();
    $table->string('external_id')->nullable()->index(); // Source's unique ID (police case number, article URL hash)
    $table->string('event_type', 40)->index();           // 'shooting', 'bombing', 'stabbing', 'robbery', 'arson', 'assault', 'other'
    $table->string('severity', 20)->default('standard'); // 'critical', 'major', 'standard', 'minor'
    $table->string('title');                              // Short description
    $table->text('description')->nullable();             // Longer description from source
    $table->string('source', 40);                        // 'polisen_handelser', 'polisen_press', 'news_svt', 'news_expressen'
    $table->string('source_url')->nullable();
    $table->decimal('lat', 10, 7)->nullable();
    $table->decimal('lng', 10, 7)->nullable();
    $table->string('deso_code', 10)->nullable()->index();
    $table->string('municipality_code', 4)->nullable();
    $table->string('municipality_name')->nullable();
    $table->string('location_text')->nullable();         // "Rinkeby, Stockholm" — raw from source
    $table->timestamp('occurred_at')->nullable()->index(); // When the event happened
    $table->timestamp('reported_at')->nullable();        // When it was reported/published
    $table->boolean('is_verified')->default(false);      // Has a human or second source confirmed it
    $table->boolean('is_geocoded')->default(false);      // Has coordinates been resolved
    $table->json('metadata')->nullable();                // Extra source-specific data
    $table->timestamps();

    $table->index(['event_type', 'occurred_at']);
    $table->index(['deso_code', 'occurred_at']);
});

// Spatial column for point geometry
DB::statement("SELECT AddGeometryColumn('public', 'crime_events', 'geom', 4326, 'POINT', 2)");
DB::statement("CREATE INDEX crime_events_geom_idx ON crime_events USING GIST (geom)");
```

### 2.4 NTU Survey Data Table

```php
Schema::create('ntu_survey_data', function (Blueprint $table) {
    $table->id();
    $table->string('area_code', 20)->index();            // Kommun code, LPO code, or region code
    $table->string('area_type', 30);                     // 'kommun', 'lokalpolisomrade', 'polisregion', 'lan', 'national'
    $table->string('area_name')->nullable();
    $table->integer('survey_year')->index();              // NTU year (e.g., 2024)
    $table->integer('reference_year')->nullable();        // Year the data refers to (usually survey_year - 1)
    $table->string('indicator_slug', 80)->index();        // 'unsafe_night', 'victimization_total', 'worry_burglary', etc.
    $table->decimal('value', 8, 2)->nullable();           // Percentage or score
    $table->decimal('confidence_lower', 8, 2)->nullable(); // 95% CI lower bound (NTU provides these)
    $table->decimal('confidence_upper', 8, 2)->nullable(); // 95% CI upper bound
    $table->integer('respondent_count')->nullable();      // Number of survey respondents in this area
    $table->string('data_source')->nullable();
    $table->timestamps();

    $table->unique(['area_code', 'area_type', 'survey_year', 'indicator_slug']);
});
```

### 2.5 DeSO ↔ Vulnerability Area Mapping Table

Pre-computed intersection between DeSO boundaries and vulnerability area polygons.

```php
Schema::create('deso_vulnerability_mapping', function (Blueprint $table) {
    $table->id();
    $table->string('deso_code', 10)->index();
    $table->foreignId('vulnerability_area_id')->constrained()->index();
    $table->decimal('overlap_fraction', 6, 4);           // 0.0000 to 1.0000 — fraction of DeSO area that overlaps
    $table->string('tier', 30);                           // Denormalized from vulnerability_areas for fast queries
    $table->timestamps();

    $table->unique(['deso_code', 'vulnerability_area_id']);
});
```

### 2.6 Kommun-to-DeSO Mapping Helper

For disaggregation, we need a lookup of which DeSOs belong to each kommun. This already exists in `deso_areas.kommun_code`, but create an index if not present:

```php
// In a migration
Schema::table('deso_areas', function (Blueprint $table) {
    $table->index('kommun_code'); // If not already indexed
});
```

---

## Step 3: Data Ingestion — BRÅ Crime Statistics

### 3.1 Download Strategy

BRÅ has **no API**. We use Excel downloads.

**Primary source:** The pre-built "Anmälda brott de senaste 10 åren" Excel file from the BRÅ website. This contains reported crime counts per kommun per year for the last 10 years, broken down by crime category.

**Download URL:** `https://bra.se/statistik/statistik-om-rattsvasendet/anmalda-brott` — look for the downloadable xlsx files:
- "Anmälda brott de senaste 10 åren" (130.7 KB) — kommun level, annual, by crime type
- "Anmälda brott i regionerna de senaste 10 åren" (27.2 KB) — region level

**Alternative:** The SOL interactive database at `https://statistik.bra.se/solwebb/action/index` allows custom queries exportable as Excel. This is more flexible but harder to automate.

**Kommun indicators from BRÅ:** `https://bra.se/statistik/indikatorer-for-kommuners-lagesbild` provides pre-computed indicators combining NTU and reported crime per kommun — very useful as a ready-made dataset.

### 3.2 Artisan Command: `ingest:bra-crime`

```bash
php artisan ingest:bra-crime [--year=2024] [--file=/path/to/excel]
```

**Flow:**

1. If no `--file`, attempt to download the Excel from BRÅ's website. Store raw file in `storage/app/data/raw/bra/`.
2. Parse the Excel using PhpSpreadsheet (`maatwebsite/excel`)
3. For each kommun × year × crime category row:
   - Extract municipality code, year, crime category, count, rate per 100k
   - Upsert into `crime_statistics`
4. Log ingestion results

**Excel parsing notes:**
- BRÅ Excel files have a specific structure — header rows, merged cells, Swedish column names. The agent needs to inspect the actual file structure and write parsing logic accordingly.
- Crime categories in BRÅ use Swedish names: "Brott mot person", "Stöld- och tillgreppsbrott", "Skadegörelsebrott", etc. Map these to our slugs.
- Some cells may contain ".." (suppressed for secrecy) or "-" (zero). Handle these as NULL.
- Rates per 100,000 may need to be computed from counts + population if not provided directly.

**Crime category mapping:**

| BRÅ Swedish name | Our slug | Real estate relevance |
|---|---|---|
| Brott mot person | `crime_person` | High — violence, threats |
| Stöld- och tillgreppsbrott | `crime_theft` | High — burglary, car theft |
| Skadegörelsebrott | `crime_damage` | Medium — vandalism |
| Rån | `crime_robbery` | Very high — most frightening |
| Sexualbrott | `crime_sexual` | High — safety perception |
| Narkotikabrott | `crime_drug` | High — proxy for open drug trade |
| Samtliga anmälda brott | `crime_total` | Overview metric |

### 3.3 BraDataService

Create `app/Services/BraDataService.php`:

- Parse BRÅ Excel files (kommun-level crime stats)
- Handle different Excel formats (the file structure may vary between years)
- Map Swedish crime category names to our slugs
- Handle missing/suppressed data gracefully
- Compute derived metrics (e.g., violent crime rate = person + robbery + sexual)

---

## Step 4: Data Ingestion — NTU Survey Data

### 4.1 Artisan Command: `ingest:ntu`

```bash
php artisan ingest:ntu [--year=2024] [--file=/path/to/excel]
```

**Primary source:** "Tabellsamling NTU 2007-2024" Excel (1.1 MB) from the NTU publication page. Contains national-level results over time.

**For geographic breakdown:** The agent should explore the NTU interactive tool at BRÅ's website and download data at lokalpolisområde level. The tool allows export, and the Excel files can be ingested.

Also ingest the "Indikatorer för kommuners lägesbild" Excel files which have pre-computed NTU + crime indicators per kommun.

**Key NTU metrics to extract:**

| NTU question | Our slug | Direction |
|---|---|---|
| Otrygghet kvällstid i bostadsområdet | `ntu_unsafe_night` | negative |
| Utsatthet för brott mot enskild person | `ntu_victimization` | negative |
| Oro för bostadsinbrott | `ntu_worry_burglary` | negative |
| Oro för misshandel/överfall | `ntu_worry_assault` | negative |
| Förtroende för polisen | `ntu_trust_police` | positive |
| Problem i bostadsområdet (störningar) | `ntu_area_problems` | negative |

### 4.2 NTU Data Levels

NTU data is available at multiple geographic levels:
- **National** — from 2006
- **Län (county)** — from 2017, Excel download available
- **Polisregion (7 regions)** — from 2017
- **Polisområde** — from 2017
- **Lokalpolisområde (94 LPOs)** — from 2017, via the interactive tool
- **Kommun** — from 2024 (expanded sample), via kommun indicators

For DeSO-level disaggregation, kommun-level data is best (finest official grain). For areas where kommun data isn't available, fall back to LPO-level.

---

## Step 5: Data Ingestion — Police Vulnerability Areas

### 5.1 Artisan Command: `ingest:vulnerability-areas`

```bash
php artisan ingest:vulnerability-areas [--year=2025]
```

**Flow:**

1. Download the GeoJSON from Polisen's website:
   `https://polisen.se/om-polisen/polisens-arbete/utsatta-omraden/` → GeoJSON zip (44 KB)
2. Parse the GeoJSON — each feature is a vulnerability area polygon with name and tier
3. Store in `vulnerability_areas` table with the geometry
4. **Spatial intersection:** For each vulnerability area polygon, find all DeSOs that overlap:

```sql
INSERT INTO deso_vulnerability_mapping (deso_code, vulnerability_area_id, overlap_fraction, tier)
SELECT
    d.deso_code,
    v.id,
    ST_Area(ST_Intersection(d.geom, v.geom)) / ST_Area(d.geom) as overlap_fraction,
    v.tier
FROM deso_areas d
CROSS JOIN vulnerability_areas v
WHERE ST_Intersects(d.geom, v.geom)
  AND v.is_current = true;
```

5. Log: how many vulnerability areas imported, how many DeSOs flagged

**GeoJSON structure:** The agent needs to inspect the downloaded file to understand the property names. Expected properties include area name, tier/classification, and possibly police region.

**CRS note:** The GeoJSON might be in SWEREF99TM (EPSG:3006). If so, reproject to WGS84 (EPSG:4326) before storing. Use PostGIS `ST_Transform`.

### 5.2 Vulnerability Flag on DeSO

After mapping, each DeSO gets a vulnerability flag based on overlap:

```sql
-- A DeSO is considered "in" a vulnerability area if ≥25% of its area overlaps
-- This threshold avoids false positives from slivers of overlap at polygon edges
```

Store the highest tier as the DeSO's vulnerability classification:
- `sarskilt_utsatt` (worst) overrides `utsatt`
- Any overlap ≥ 25% counts

---

## Step 6: Disaggregation — Kommun Crime Rates to DeSO

### 6.1 The Problem

BRÅ crime data is at kommun level. A kommun like Stockholm has ~960,000 people across ~350+ DeSOs ranging from Östermalm (very safe, very rich) to Rinkeby (särskilt utsatt område). Flat distribution of the kommun crime rate to all DeSOs would be nonsense.

### 6.2 Approach: Demographic-Weighted Disaggregation

This is a simplified version of the Kronofogden dasymetric mapping described in `data_pipeline_specification.md`. We use SCB demographics that we already have at DeSO level to estimate how crime distributes within a kommun.

**Core idea:** Within a kommun, DeSOs with lower income, higher unemployment, lower education, and especially those flagged as vulnerability areas should receive a higher share of the kommun's total crime.

**Model (PHP-native, no Python needed for v1):**

For each kommun:
1. Get the kommun's total crime rate per 100k (from `crime_statistics`)
2. Get all DeSOs within this kommun
3. For each DeSO, compute a **crime propensity weight** using available demographic indicators:

```php
$weight = 0.0;

// Each factor is the DeSO's normalized value (0-1 percentile) for that indicator
// Higher weight = higher expected crime share

$weight += (1.0 - $incomePercentile) * 0.35;       // Lower income → more crime
$weight += (1.0 - $employmentPercentile) * 0.20;    // Lower employment → more crime
$weight += (1.0 - $educationPercentile) * 0.15;     // Lower education → more crime
$weight += $vulnerabilityFlag * 0.30;                // Utsatt område → much more crime (binary: 0 or 1)

// Vulnerability tier bonus
if ($tier === 'sarskilt_utsatt') $weight += 0.20;
```

4. Normalize weights within the kommun so they sum to 1.0
5. Distribute the kommun crime rate proportionally: `deso_rate = kommun_rate * (deso_weight / sum_of_weights) * num_desos`
6. **Constraint:** The population-weighted average of DeSO rates within a kommun must equal the known kommun rate. Scale accordingly.

**Why this works well enough for v1:**
- Income alone explains ~60-70% of crime rate variance at kommun level (from data_pipeline_specification.md)
- Adding the vulnerability flag captures the extreme tail (areas where organized crime has a structural presence)
- We're not publishing this as fact — we're using it for a composite score where crime is one of many factors
- Better estimates come from the Python regression model (v2) described in data_pipeline_specification.md

**When to run:** After BRÅ data ingestion AND after SCB demographics are loaded for the same year.

### 6.3 NTU Disaggregation

NTU data at lokalpolisområde (LPO) level is coarser than kommun (94 LPOs vs 290 kommuner). But NTU captures perceived safety which is different from reported crime.

For DeSOs within an LPO, use the same demographic-weighting approach:
- DeSOs with worse socioeconomic indicators get a higher share of the LPO's insecurity
- Vulnerability-flagged DeSOs get an additional penalty

For kommun-level NTU data (available from 2024), use the same kommun→DeSO approach as for BRÅ crime rates.

### 6.4 Artisan Command: `disaggregate:crime`

```bash
php artisan disaggregate:crime [--year=2024]
```

This command:
1. Reads kommun-level crime data from `crime_statistics`
2. Reads DeSO-level demographics from `indicator_values` (income, employment, education)
3. Reads vulnerability mappings from `deso_vulnerability_mapping`
4. Computes estimated DeSO-level crime rates
5. Stores results as `indicator_values` rows for the crime indicator slugs
6. Also disaggregates NTU data to DeSO level

---

## Step 7: Crime Indicators

### 7.1 New Indicators

Add to the `indicators` table:

| slug | name | unit | direction | weight | category | source |
|---|---|---|---|---|---|---|
| `crime_violent_rate` | Violent Crime Rate | per_100k | negative | 0.08 | crime | bra |
| `crime_property_rate` | Property Crime Rate | per_100k | negative | 0.06 | crime | bra |
| `crime_total_rate` | Total Crime Rate | per_100k | negative | 0.04 | crime | bra |
| `perceived_safety` | Perceived Safety (NTU) | percent | positive | 0.07 | safety | bra_ntu |
| `vulnerability_flag` | Police Vulnerability Area | flag | negative | 0.10 | crime | polisen |

**Notes on the vulnerability flag indicator:**
- This is a binary indicator (0 or 1), not a continuous variable
- `raw_value`: 0 = not in a vulnerability area, 1 = utsatt, 2 = särskilt utsatt
- `normalized_value`: 0.0 = särskilt utsatt, 0.5 = utsatt, 1.0 = not flagged
- Weight 0.10 is intentionally high — this is the single most decisive signal for Swedish housing sentiment
- DeSOs with partial overlap (< 25% area) get 0, full overlap gets the full flag

**Crime rate composition:**
- `crime_violent_rate` = crimes against persons + robbery + sexual crimes per 100k
- `crime_property_rate` = theft + criminal damage per 100k
- `crime_total_rate` = all reported crimes per 100k (already available from BRÅ)

**Perceived safety:**
- Inverted NTU "otrygghet kvällstid" — we store "% who feel safe" as the positive metric
- Raw value: percentage who feel safe (100 - otrygghet percentage)
- This can diverge from actual crime rates — that divergence is itself informative

### 7.2 Weight Rebalancing

After adding crime indicators, the total active weight budget:

| Category | Previous | New |
|---|---|---|
| Income (SCB) | 0.20 | 0.15 |
| Employment (SCB) | 0.10 | 0.08 |
| Education — demographics (SCB) | 0.10 | 0.08 |
| Education — school quality (Skolverket) | 0.25 | 0.19 |
| **Crime (BRÅ)** | **0.00** | **0.18** |
| **Safety (NTU)** | **0.00** | **0.07** |
| **Vulnerability (Polisen)** | **0.00** | **0.10** |
| Unallocated (debt, POI, transit) | 0.35 | 0.15 |

Crime + safety + vulnerability = 0.35 total — the largest single block. This reflects reality: safety is the dominant consumer concern. School quality (0.19) remains the second largest block.

Update weights via seeder/migration. Don't hardcode.

### 7.3 Recompute Scores

```bash
php artisan normalize:indicators --year=2024
php artisan compute:scores --year=2024
```

The composite scores now reflect crime risk. Map colors should shift dramatically — vulnerability areas should turn visibly purple.

---

## Step 8: Sidebar Crime Section

### 8.1 Crime Data in Sidebar

When a DeSO is selected, add a "Safety & Crime" section to the sidebar after the existing indicator breakdown:

**If the DeSO is in a vulnerability area:**

```
┌─────────────────────────────────────┐
│ ⚠️  Polisens Utsatt Område          │
│                                     │
│ This DeSO overlaps with             │
│ "Rinkeby" — classified as           │
│ SÄRSKILT UTSATT (2025)              │
│                                     │
│ 60% of all shootings 2022-2024 are  │
│ connected to areas on this list.    │
└─────────────────────────────────────┘
```

Use a warning-colored card (amber for utsatt, red for särskilt utsatt). This should be prominent — it's the most impactful single signal.

**Crime rate breakdown:**

```
┌─────────────────────────────────────┐
│ Crime & Safety                      │
│                                     │
│ Violent crime    ████████░░  81st   │
│                  (est. 1,420/100k)  │
│ Property crime   ███████░░░  68th   │
│                  (est. 3,200/100k)  │
│ Perceived safety ██████░░░░  55th   │
│                  (72% feel safe)    │
│                                     │
│ Note: Crime rates are estimated     │
│ from kommun-level data using        │
│ demographic weighting.              │
└─────────────────────────────────────┘
```

**Important:** Add a subtle note that crime rates are estimated, not measured at DeSO level. Transparency builds trust.

### 8.2 Future: Recent Incidents Section (Placeholder)

Add a collapsible section with placeholder text:

```
┌─────────────────────────────────────┐
│ Recent Incidents                    │
│ Coming soon — real-time tracking    │
│ of police reports and news.         │
└─────────────────────────────────────┘
```

This section will later show point markers from the `crime_events` table. For now, just the placeholder so the UI structure is ready.

### 8.3 Crime API Endpoint

Create an endpoint for crime details per DeSO:

```php
Route::get('/api/deso/{desoCode}/crime', [DesoController::class, 'crime']);
```

Returns:
- Estimated crime rates (violent, property, total)
- NTU perceived safety score
- Vulnerability area info (name, tier, overlap fraction) if applicable
- Kommun-level actual crime rates (for reference)

---

## Step 9: Map Vulnerability Layer (Optional Enhancement)

### 9.1 Vulnerability Area Overlay

When a DeSO in a vulnerability area is selected, optionally show the vulnerability area polygon as a semi-transparent overlay with a dashed red border on the map. This gives the user visual context for how large the vulnerability zone is relative to the DeSO.

This is polish — implement only if the core pipeline works smoothly.

**Implementation:**
- New OpenLayers vector layer (like school markers)
- Load vulnerability area geometry from a new endpoint: `GET /api/vulnerability-areas/{id}/geometry`
- Show only when a DeSO within a vulnerability area is selected
- Style: red dashed border, 10% red fill opacity
- Clear when deselecting or selecting a non-vulnerable DeSO

---

## Step 10: Full Pipeline Test

### 10.1 Run Everything

```bash
# 1. Ingest BRÅ crime statistics (from Excel)
php artisan ingest:bra-crime --year=2024

# 2. Ingest NTU survey data (from Excel)
php artisan ingest:ntu --year=2024

# 3. Import vulnerability area polygons (from GeoJSON)
php artisan ingest:vulnerability-areas --year=2025

# 4. Disaggregate crime data from kommun to DeSO
php artisan disaggregate:crime --year=2024

# 5. Normalize all indicators (including new crime ones)
php artisan normalize:indicators --year=2024

# 6. Recompute composite scores
php artisan compute:scores --year=2024
```

### 10.2 Database Verification

```sql
-- Check crime data import
SELECT year, crime_category, COUNT(*), AVG(rate_per_100k)
FROM crime_statistics
WHERE year = 2024
GROUP BY year, crime_category;

-- Check vulnerability areas
SELECT tier, COUNT(*) FROM vulnerability_areas WHERE is_current = true GROUP BY tier;
-- Expect: 46 utsatt + 19 särskilt utsatt = 65 total

-- Check DeSO vulnerability mapping
SELECT dvm.tier, COUNT(DISTINCT dvm.deso_code)
FROM deso_vulnerability_mapping dvm
JOIN vulnerability_areas va ON va.id = dvm.vulnerability_area_id
WHERE va.is_current = true AND dvm.overlap_fraction >= 0.25
GROUP BY dvm.tier;
-- Expect: several hundred DeSOs flagged

-- Check crime indicators at DeSO level
SELECT i.slug, COUNT(iv.id), AVG(iv.raw_value), MIN(iv.raw_value), MAX(iv.raw_value)
FROM indicator_values iv
JOIN indicators i ON i.id = iv.indicator_id
WHERE i.category = 'crime' AND iv.year = 2024
GROUP BY i.slug;

-- Sanity: vulnerability areas should have lowest scores
SELECT cs.deso_code, da.kommun_name, cs.score,
       CASE WHEN dvm.id IS NOT NULL THEN dvm.tier ELSE 'none' END as vuln_tier
FROM composite_scores cs
JOIN deso_areas da ON da.deso_code = cs.deso_code
LEFT JOIN deso_vulnerability_mapping dvm ON dvm.deso_code = cs.deso_code
    AND dvm.overlap_fraction >= 0.25
WHERE cs.year = 2024
ORDER BY cs.score ASC LIMIT 20;
-- Expect: bottom-scoring DeSOs should heavily overlap with vulnerability areas

-- Inverse sanity: Danderyd, Lidingö should still be green
SELECT cs.deso_code, da.kommun_name, cs.score
FROM composite_scores cs
JOIN deso_areas da ON da.deso_code = cs.deso_code
WHERE cs.year = 2024
ORDER BY cs.score DESC LIMIT 10;
-- Expect: Danderyd, Lidingö, Täby, Lomma, Vellinge

-- Check that crime_events table exists but is empty
SELECT COUNT(*) FROM crime_events;
-- Expect: 0 (future use only)
```

### 10.3 Visual Checklist

- [ ] Map colors have shifted — vulnerability areas now visibly purple
- [ ] The contrast between safe and unsafe areas is more dramatic than before
- [ ] Sidebar shows "Safety & Crime" section with estimated rates
- [ ] DeSOs in vulnerability areas show a prominent warning card (amber/red)
- [ ] Vulnerability tier badge (utsatt / särskilt utsatt) is clearly visible
- [ ] Crime indicator bars show in the indicator breakdown section
- [ ] The estimated vs actual crime rate note is visible (transparency)
- [ ] "Recent Incidents — Coming soon" placeholder is in the sidebar
- [ ] Admin page shows new crime indicators with correct weights
- [ ] Changing crime weight in admin and recomputing shifts the map
- [ ] Danderyd/Lidingö remain green, Rinkeby/Rosengård turn deep purple
- [ ] Composite score now has 6 categories of indicators feeding it
- [ ] crime_events table exists but is empty (for future use)

---

## Notes for the Agent

### No API — Excel Only

BRÅ explicitly states they have no public API. All data is via Excel downloads from their website and the SOL interactive database. The agent must download the Excel files and parse them with PhpSpreadsheet.

### Excel File Exploration

The BRÅ Excel files have unpredictable formatting — merged header rows, Swedish column names, footnotes mixed with data. The agent should:
1. Download the actual file first
2. Inspect its structure (sheets, columns, data range)
3. Write parsing logic specific to that structure
4. Handle variations between years if the format changed

### Vulnerability Area GeoJSON — Incredible Data

Polisen publishes actual polygon boundaries for the 65 vulnerability areas as downloadable GeoJSON and Shapefile. This is rare and incredibly valuable. Download from:
`https://polisen.se/om-polisen/polisens-arbete/utsatta-omraden/`

The GeoJSON may need CRS transformation from SWEREF99TM to WGS84.

### NTU Interactive Tool

The NTU "Skapa din egen tabell" tool at BRÅ's website allows exporting data at lokalpolisområde level. The agent should explore this tool to understand what's available and how to export. The tool URL:
`https://bra.se/statistik/statistik-fran-enkatundersokningar/nationella-trygghetsundersokningen/skapa-din-egen-tabell-ntu`

### The Disaggregation Is An Estimate

Be very clear in the UI that DeSO-level crime rates are ESTIMATED from kommun-level data. This is not measurement — it's a statistical model. Always show the kommun-level actual rate alongside for context. Users who know their area will spot if the estimate feels wrong, and transparency protects credibility.

### Crime Counting Quirks

Sweden counts every individual offence separately (a mugging with theft = 2 crimes). Attempted offences counted. Events later found to not be criminal still counted. This is consistent across all kommuner, so relative comparisons are fine. But absolute rates look higher than other countries — don't compare internationally.

### What NOT to do

- Don't try to build a BRÅ API client — there is no API
- Don't show vulnerability area names as the dominant label in the sidebar — use "Safety & Crime" framing, not "This is a ghetto" framing
- Don't populate the `crime_events` table — schema only for now
- Don't implement real-time crime event scraping or display — future task
- Don't show individual crime incidents or personal data — aggregate only
- Don't use the `foreign_background_pct` indicator in the disaggregation model — use income, employment, education only (legal compliance)
- Don't weight vulnerability flag higher than 0.10 — it's powerful but binary signals need caps

### What to prioritize

1. Get vulnerability areas imported first — it's the easiest (GeoJSON → PostGIS) and highest impact
2. Then BRÅ kommun-level crime rates from Excel
3. Then the disaggregation to DeSO
4. NTU survey data can come last — it's a refinement
5. The sidebar crime section matters a lot for user trust — get it right
6. Make sure the `crime_events` table schema is solid — we'll thank ourselves later

### Legal Compliance Reminder

- All data is aggregate public statistics — no individual records
- No names, no ethnicity, no religion in any crime-related display
- The vulnerability area classification is public data published by Polisen themselves
- The disaggregation model uses only socioeconomic indicators (income, employment, education) — NOT ethnicity or country of origin
- Client-facing labels: "Elevated crime rate" or "Police vulnerability area" — factual, not inflammatory

### Update CLAUDE.md

Add:
- BRÅ data access patterns (no API, Excel parsing, SOL database)
- Vulnerability area GeoJSON download URL and structure
- NTU data levels and export workflow
- Disaggregation model coefficients and approach
- Any Excel parsing gotchas discovered during implementation