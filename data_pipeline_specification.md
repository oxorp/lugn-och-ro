# Data Pipeline Specification — Swedish Real Estate Prediction Platform

**Version 1.0 — February 2026**
**For Claude Code Agent Implementation**
**Stack: Laravel 11 + Inertia.js + React + PostgreSQL/PostGIS**

---

## 1. Business Context

### 1.1 Product Overview

This platform is a real estate prediction tool for the Swedish market. It combines crime statistics, demographic data, school quality metrics, financial distress indicators, and points of interest into a composite **Neighborhood Trajectory Score (0–100)** that predicts whether an area is trending upward or downward in terms of desirability and property value over a 3–5 year horizon.

No equivalent product exists in Sweden today. The closest US equivalent is NeighborhoodScout (2,723 data elements per neighborhood, $5K+ annual enterprise licenses). In Sweden, Booli.se offers basic analytics and Hemnet.se is a listings platform, but neither provides a predictive scoring tool integrating the data layers described here.

### 1.2 Target Customers

| Segment | Use Case | Price Sensitivity |
|---|---|---|
| Real estate investors (buy-to-let, flipping) | Predictive neighborhood scores, crime trends, demographic shifts | Medium |
| Mortgage lenders / banks | Risk scoring by area, portfolio analysis, compliance | Low (big budgets) |
| Insurance companies | Crime risk, property damage risk by micro-area | Low (critical for pricing) |
| Real estate agents (mäklare) | Neighborhood reports to show buyers | High (volume play) |
| Corporate relocation | Area quality reports for employees moving to Sweden | Medium |
| Individual homebuyers | Consumer-facing version, cheaper tier | High (massive volume) |

### 1.3 Legal Constraints

**Critical legal context for all agents working on this codebase:**

In **February 2025**, the Swedish Supreme Court ruled that bulk collection of court judgments for searchable databases violates GDPR Article 10 (criminal conviction data processing). This shut down Lexbase, Verifiera, and Acta Publica. IMY (the Swedish privacy authority) is actively auditing similar services.

**Design rules:**
- The platform must **NOT** build searchable databases linking individual names to criminal convictions
- The platform must **NOT** use individual-level personal data as model features
- All data inputs must be **aggregate-level statistics** — no individual-level personal data
- The scoring model uses data from government agencies (SCB, BRÅ, Kronofogden, Skolverket, Polisen), open data platforms (OpenStreetMap), and commercial APIs (Google Places) as needed
- The constraint is on individual-level data, not on source type — any aggregate data source that improves prediction quality is valid
- No individual-level personal data is stored or processed

**Client-facing output rule:** The client sees "Elevated Risk: declining school performance, rising crime trend, high estimated debt rate" — never individual names, ethnic counts, or religious building proximity as named features. The underlying model may use any legal public aggregate data, but user-facing labels must express measurable socioeconomic indicators only.

---

## 2. Spatial Framework: DeSO and H3

### 2.1 What Are DeSOs?

**DeSO** (Demografiska statistikområden) is the primary geographic unit for this platform. Created and maintained by Statistics Sweden (SCB), DeSOs subdivide Sweden into **5,984 areas** of approximately **700–2,700 inhabitants** each. Boundaries respect geographic features (streets, rivers, railways), urbanicity, and electoral districts. First introduced in 2018, applied retrospectively to data from 2004.

DeSOs are the finest-grained geographic unit at which SCB publishes demographic, income, employment, education, and housing data. They are the backbone of this system.

| Property | Value |
|---|---|
| Total count | 5,984 areas covering all of Sweden |
| Population per unit | ~700–2,700 inhabitants |
| Boundary logic | Streets, rivers, railways, urbanicity, electoral districts |
| First available | 2018 (retroactively applied to 2004+) |
| Maintained by | SCB (Statistics Sweden) |
| Geometry format | Shapefile / GeoPackage from SCB |
| Parent geography | RegSO → Kommun → Län |
| API access | api.scb.se (PX-Web API, free) |
| Lookup tool | SCB REGINA web map (regina.scb.se) |

### 2.2 What Is H3?

**H3** is Uber's open-source hexagonal hierarchical spatial index. It divides the earth's surface into hexagonal cells at 16 resolutions (0–15). Each cell has a unique 64-bit index.

H3 is used here as the **universal spatial grid** that all data sources map onto, enabling cross-source joins and consistent spatial queries. The key advantage over irregular polygons like DeSOs: hexagonal grids have **uniform adjacency** (every cell has exactly 6 neighbors at equal distance), making spatial smoothing, interpolation, and neighbor analysis mathematically cleaner.

### 2.3 H3 Resolutions

| Resolution | Avg Hex Area | Use Case |
|---|---|---|
| 7 | ~5.16 km² | Municipal-level aggregation, display zoom-out |
| **8** | **~0.74 km²** | **Primary analysis resolution — closest to DeSO scale** |
| 9 | ~0.105 km² | POI density analysis, micro-area detail |
| 10 | ~0.015 km² | Street-level POI proximity, property-level lookups |

**Resolution 8 is the primary working resolution.** At ~0.74 km² per hex, it aligns well with DeSO areas (which average ~1–5 km² in urban zones). Most DeSOs overlap with 2–15 resolution-8 hexes depending on density.

### 2.4 DeSO-to-H3 Mapping Strategy

DeSO boundaries are irregular polygons. H3 cells are hexagons. They don't align perfectly. The mapping strategy depends on the data type:

#### Approach A: Area-Weighted Disaggregation (for rate/count data from DeSO)

When a data value represents a rate or count for an entire DeSO (e.g., median income, % foreign background), distribute the value to H3 cells based on the proportion of the DeSO polygon each hex covers.

For **rates** (percentages): assign the DeSO rate uniformly to all contained hexes. For **counts**: distribute proportionally by intersection area.

#### Approach B: Centroid Assignment (for point data)

For point-based data (school locations, POIs, crime incidents), compute the H3 index of each point's coordinates at the target resolution. `h3.latLngToCell(lat, lng, resolution)` gives the hex index directly.

#### Approach C: Population-Weighted Disaggregation (for kommun-level data)

Some datasets (notably Kronofogden debt statistics) are only available at **kommun** (municipality) level, which is far too coarse — a kommun like Stockholm contains both Djursholm and Rinkeby. Flat distribution across DeSOs would be meaningless.

**Method:**
1. Build a regression model at kommun level:
   `kommun_debt_rate ~ f(median_income, foreign_background_pct, social_assistance_pct, unemployment_pct, education_level, housing_type)`
2. Train on all 290 municipalities using Kronofogden kommun-level data as ground truth
3. Apply coefficients to DeSO-level values of the same variables → estimated DeSO-level debt rates
4. Constrain DeSO estimates so they sum to the known kommun total (small area estimation)

This is called **dasymetric mapping**. The income variable alone captures ~60–70% of the variance. Adding social assistance rate pushes to ~80%.

#### The Pipeline Join: DeSO → H3

1. Download DeSO boundary shapefiles from SCB
2. For each DeSO polygon, compute all overlapping H3 resolution-8 indexes using `h3.polygonToCells()`
3. Build a DeSO→H3 lookup table with area intersection weights
4. When loading any DeSO-level dataset, join via this lookup table to produce H3-level values
5. When loading kommun-level data, first disaggregate to DeSO (Approach C), then map to H3
6. When loading point data, assign directly to H3 (Approach B)

**Result:** All data sources converge on a single H3 resolution-8 grid, enabling cross-source joins on a shared spatial key.

---

## 3. Data Sources

### 3.1 Crime Data (BRÅ)

BRÅ (Brottsförebyggande rådet / Swedish National Council for Crime Prevention) is the primary source for crime statistics.

#### 3.1.1 Reported Offences

| Field | Detail |
|---|---|
| URL | https://bra.se/statistik/kriminalstatistik/anmalda-brott |
| Format | Excel (.xlsx) downloadable |
| Granularity | 94 local police districts (polisområden) |
| Coverage | All reported offences by type: violence, sexual, theft, robbery, vandalism, fraud, drug offences |
| Update frequency | Annually (final), quarterly (preliminary) |
| Historical depth | 1950s onwards nationally; police district level from ~2000s |
| Cost | Free |

**Counting note:** Sweden counts every individual offence separately (multiple offences on one occasion = multiple records), attempted offences counted alongside completed ones, all reported events recorded even if later not criminal. This inflates raw numbers versus other countries.

#### 3.1.2 Swedish Crime Survey (NTU)

| Field | Detail |
|---|---|
| URL | https://bra.se/statistik/statistik-utifran-brottstyper/nationella-trygghetsundersokningen.html |
| Method | Self-reported victimization survey, ~200,000 respondents aged 16–84 |
| Granularity | 94 local police districts; municipal level since 2024 |
| Value | Captures unreported crime, perceived safety, trust in police |
| Update frequency | Annually |
| Cost | Free |

#### 3.1.3 Police Vulnerability Classifications

| Field | Detail |
|---|---|
| URL | https://polisen.se/om-polisen/polisens-arbete/utsatta-omraden/ |
| Granularity | Named neighborhoods (65 areas as of 2025) |
| Tiers | 3 levels: Utsatt (vulnerable), Riskområde (risk), Särskilt utsatt (particularly vulnerable, 19 areas) |
| Key stat | ~60% of all shootings 2022–2024 linked to these areas |
| Geocoding | Named areas, manually mappable to DeSO/H3 via polygon overlay |
| Cost | Free |

#### 3.1.4 Pipeline: Crime → H3

Police districts don't align with DeSOs. Mapping approach:
- Download police district boundary polygons (from Polisen or SCB regional divisions)
- Intersect police district polygons with H3 resolution-8 cells
- Assign crime rates to H3 cells via area-weighted disaggregation
- For vulnerability classifications: geocode the 65 named neighborhoods, overlay with H3, flag all intersecting hexes

Crime data is the coarsest layer (94 districts for all of Sweden). NTU at municipal level from 2024 adds finer perceived-safety resolution.

---

### 3.2 Demographics (SCB)

SCB (Statistiska centralbyrån / Statistics Sweden) provides the core demographic feature set at DeSO level via a free API.

#### 3.2.1 Core DeSO-Level Variables

| Variable | SCB Table / API Path | Notes |
|---|---|---|
| Population by foreign/Swedish background | BE0101 series via api.scb.se | Foreign background = foreign-born OR born in Sweden with two foreign-born parents |
| Country/region of birth composition | BE0101 series (municipal level) | For Approach C disaggregation |
| Median disposable income | HE0110 series at DeSO | Key predictor for financial distress modeling |
| Employment rate / unemployment | AM0207 at DeSO | Available by age and sex |
| % receiving social assistance (försörjningsstöd) | SO0204 at DeSO/municipal | Strong positive correlator with debt |
| Education level (% without gymnasie) | UF0506 at DeSO | Long-term economic trajectory proxy |
| Housing type (hyresrätt vs bostadsrätt/villa) | BO0104 at DeSO | Tenure type distribution |
| Household composition | HE0111 at DeSO | Single-person, families with children, etc. |
| Population flows (in/out migration) | BE0101 at municipal | Leading area trajectory indicator |

#### 3.2.2 API Access

| Field | Detail |
|---|---|
| Base URL | `https://api.scb.se/OV0104/v1/doris/en/ssd/` |
| Protocol | PX-Web API (JSON-stat2 responses) |
| Auth | None required (free, open) |
| Rate limits | Reasonable use; no published hard limits |
| PHP client | Use `Illuminate\Support\Facades\Http` — standard JSON POST requests |
| Interactive explorer | https://www.statistikdatabasen.scb.se |
| DeSO boundary files | Shapefile/GeoPackage from SCB geodata portal |
| Map lookup | SCB REGINA: regina.scb.se |

#### 3.2.3 Pipeline: SCB DeSO → H3

Cleanest path. SCB data is natively at DeSO level: fetch via API → join to DeSO-H3 lookup table → H3 grid. No disaggregation needed. For municipal-level variables (country-of-birth, population flows), use Approach C first, then map to H3.

---

### 3.3 School Quality (Skolverket)

Skolverket (Swedish National Agency for Education) provides granular school performance data via a free, daily-updated API. School quality is arguably the single strongest predictor of real estate prices.

#### 3.3.1 Available Data

| Variable | Notes |
|---|---|
| Average grades (meritvärde) | Per school unit; composite score of best 16/17 subjects |
| % students achieving goals | Pass rates by subject and school |
| Teacher qualifications | % certified teachers (behöriga lärare) |
| Student-teacher ratios | By school unit |
| School type and program offerings | Grundskola, gymnasie, friskola, etc. |
| Geocoded location | Coordinates per school unit |

#### 3.3.2 API Access

| Field | Detail |
|---|---|
| API docs | https://skolverket.se/om-oss/oppna-data/api-for-skolenhetsregistret |
| MCP server | https://skolverket-mcp.onrender.com/mcp (community-built, MIT) |
| Update frequency | Daily |
| Cost | Free |

#### 3.3.3 Pipeline: Skolverket → H3

Schools are point data with coordinates: fetch from API → `h3.latLngToCell(lat, lng, 9)` → aggregate per resolution-8 hex (average or best/worst). Compute school quality index per hex: meritvärde (0.5) + goal achievement (0.3) + teacher qualifications (0.2). Hexes with no schools inherit nearest school's score via H3 k-ring neighbor lookup.

---

### 3.4 Financial Distress (Kronofogden)

Kronofogden (Swedish Enforcement Authority) handles debt collection, payment orders, and evictions. Open data at kommun and län level, explicitly open for commercial reuse.

#### 3.4.1 Available Datasets

| Dataset | Granularity | Time Span | Format |
|---|---|---|---|
| Skuldsatta privatpersoner (indebted individuals) | Kommun + Län | 2010–2025 | XLSX (4MB) |
| % adult population with debt at Kronofogden | Kommun | 2010–2025 | XLSX |
| Vräkningar (evictions) | Kommun + Län | 2010–2025 | XLSX |
| Skuldsanering (debt restructuring) applications | Kommun + Län | 2010–2025 | XLSX |
| Betalningsföreläggande (payment orders) | Kommun + Län | 2010–2025 | XLSX |
| Snabblån (payday loans — subset) | National + regional | 2015+ | XLSX |
| Fordonsrelaterade skulder (vehicle debts) | Regional | 2015+ | XLSX |

#### 3.4.2 Data Portal

| Field | Detail |
|---|---|
| Open data portal | https://kronofogden.se/om-kronofogden/statistik/oppna-data-psidata |
| Interactive comparison | Statistikrobot at https://kronofogden.se/81975.html |
| European portal | Data auto-published to oppnadata.se and data.europa.eu |
| License | Open, explicitly free for commercial reuse |
| Key stat (2026) | 154 billion SEK total debt, 449,703 people, 12% annual increase |

#### 3.4.3 Pipeline: Kronofogden → DeSO → H3

**This is the most important disaggregation problem in the pipeline.** Kronofogden data is at kommun level only.

**Step 1 — Regression model at kommun level:**
- Dependent variable: `kommun_debt_rate` (from Kronofogden, all 290 municipalities)
- Independent variables (from SCB, available at both kommun and DeSO level): median disposable income, % foreign background, % receiving social assistance, unemployment rate, % without gymnasium education, % hyresrätt
- Model type: OLS or gradient-boosted regression; cross-validate on kommun data

**Step 2 — Apply model to DeSO level:**
- Feed DeSO-level values of same independent variables into trained model
- Produces `estimated_debt_rate` per DeSO

**Step 3 — Constrain to kommun totals:**
- Scale DeSO estimates proportionally so they sum to the known kommun-level Kronofogden rate
- Standard small area estimation: estimates anchored to real aggregate data

**Step 4 — Map to H3:**
- Join DeSO-level estimated debt rates to H3 via DeSO→H3 lookup table

**Validation:** Cross-reference estimated high-debt DeSOs against Police vulnerability classifications. High-debt areas should strongly overlap with utsatta områden.

---

### 3.5 Points of Interest (OpenStreetMap + Google)

#### 3.5.1 Negative Marker POIs

| POI Type | OSM Tag / Source | Signal |
|---|---|---|
| Mosques / Islamic prayer halls | `amenity=place_of_worship, religion=muslim`; SST; Bolagsverket | Demographic composition proxy |
| Gambling venues | Svenska Spel (Casino Cosmopol), ATG betting shops | Financial distress correlation |
| Pawn shops (pantbank) | OSM: `shop=pawnbroker`; Google Places | Financial distress marker |
| Payday loan offices | Finansinspektionen licensed lenders + Google Maps | Most online-only; physical offices exist |
| Late-night fast food clusters | OSM: `amenity=fast_food` + `cuisine=kebab`; Google Places | Nighttime economy/disturbance proxy |
| Cash-intensive businesses | Bolagsverket + OSM | Money laundering indicators |

#### 3.5.2 Positive Marker POIs

| POI Type | Source | Signal |
|---|---|---|
| High-performing schools (top quartile) | Skolverket API | Strong property value driver |
| Premium grocery (Paradiset, upscale ICA) | OSM / chain data | Affluence indicator |
| Gyms, padel courts, premium fitness | Google Places | Active lifestyle demographics |
| Specialty coffee / high-end cafés | OSM | Gentrification indicator |
| International schools | Skolverket | Expat / high-income family signal |
| Waterfront / green space proximity | Lantmäteriet / OSM | Consistent premium in Swedish market |

#### 3.5.3 POI Sources

| Source | URL | Notes |
|---|---|---|
| OpenStreetMap Overpass API | https://overpass-api.de/api/interpreter | Free; ~300+ mosques; all POI types |
| Google Places API | https://maps.googleapis.com/maps/api/place/ | Paid; more complete for commercial POIs |
| SST (faith communities agency) | https://www.myndighetensst.se/ | Islamic organizations with state funding |
| Bolagsverket (company register) | https://www.bolagsverket.se/ | Registered organizations searchable |
| Systembolaget locations | https://www.systembolaget.se/butiker-ombud/ | State liquor stores (public list) |

#### 3.5.4 Pipeline: POI → H3

All POIs are point data: query Overpass/Google → `h3.latLngToCell(lat, lng, 9)` → aggregate density counts per resolution-8 hex → compute composite POI score (negative markers subtract, positive markers add).

**Normalization:** POI indicators use urbanity-stratified percentile ranking. Rural DeSOs are ranked against other rural DeSOs, urban against urban. This prevents rural areas from being unfairly penalized for lower absolute amenity counts that are appropriate for their context.

---

### 3.6 Additional Data Sources

#### 3.6.1 Public Transport Accessibility

| Field | Detail |
|---|---|
| Source | GTFS feeds from SL (Stockholm), Västtrafik (Gothenburg), Skånetrafiken (Malmö/Scania) |
| Data | Stop locations, route frequencies, travel times |
| Cost | Free (open data) |
| Pipeline | Commute time to nearest city center per H3 cell; stop density per hex |

#### 3.6.2 Property Market Data

| Source | Detail |
|---|---|
| Svensk Mäklarstatistik | Transaction prices, address-level; paid for commercial use |
| Booli.se | Listed prices, sold prices, time-on-market; some free, paid API |
| SCB housing price index | Regional price indices; free |
| Allabrf.se | BRF co-op financials: monthly fees, debt per sqm; paid |

#### 3.6.3 Land and Construction

| Source | Detail |
|---|---|
| Lantmäteriet | Property boundaries, cadastral data; some free, commercial license for full |
| Municipal detaljplaner | Zoning and planned construction; free from municipality websites |
| SCB construction permits | New building permits by municipality; free |

#### 3.6.4 Credit Bureau Data (Commercial, Optional)

UC (Experian Sweden) or Bisnode (Dun & Bradstreet) sell aggregate credit risk indices by geographic area at finer granularity than Kronofogden kommun-level data. This would partially replace or validate the Kronofogden disaggregation model. Creditsafe also operates in Sweden for business credit. Contact these providers for commercial API access and pricing.

---

## 4. Pipeline Architecture

### 4.1 Stack Overview

This is a monolithic Laravel application. There's no need for microservices — the workload is periodic batch ingestion + a read-heavy API/frontend. Laravel handles everything.

| Component | Technology | Rationale |
|---|---|---|
| **Backend framework** | Laravel 11 | Scheduled commands for ingestion, Eloquent for data, queues for heavy jobs |
| **Frontend framework** | React via Inertia.js | SPA feel without a separate API; shared auth/session |
| **Database** | PostgreSQL 16 + PostGIS | Spatial queries, H3 extension available, mature Laravel support |
| **H3 in database** | `h3-pg` PostgreSQL extension | H3 functions directly in SQL (h3_lat_lng_to_cell, h3_polygon_to_cells, etc.) |
| **H3 in PHP** | Shell out to Python `h3` library for boundary processing jobs, or use `neatlife/php-h3` | Boundary file processing is a one-time job; ongoing lookups use h3-pg in SQL |
| **Geodata processing** | Python scripts (called from Laravel commands) | GeoPandas + Shapely for DeSO boundary → H3 lookup table generation |
| **Map rendering** | Deck.gl H3HexagonLayer or react-map-gl + Mapbox | Native H3 hex rendering; Inertia passes score data as page props |
| **File storage** | Local disk or S3 | Raw XLSX/JSON downloads in `storage/app/data/raw/` |
| **Queue** | Laravel Queue (database or Redis driver) | For heavy ingestion jobs |
| **Scheduler** | Laravel `schedule:run` via cron | Periodic data fetches |
| **XLSX parsing** | PhpSpreadsheet (`maatwebsite/excel`) | Parse BRÅ and Kronofogden XLSX files |
| **HTTP client** | `Illuminate\Support\Facades\Http` | Fetch SCB API, Skolverket API, Overpass API |

### 4.2 Database Schema (Core Tables)

```
── Spatial lookup ──────────────────────────────────────
deso_areas
  id, deso_code (varchar, indexed), name, kommun_code, lan_code,
  geometry (PostGIS geometry), population, area_km2

h3_cells
  id, h3_index (varchar(15), unique, indexed), resolution (int),
  center_lat, center_lng

deso_h3_mapping
  id, deso_code (FK), h3_index (FK), area_weight (decimal)
  -- Pre-computed: what fraction of this DeSO falls in this hex

── Data layers (one row per H3 cell per time period) ───
crime_scores
  id, h3_index (FK), year, quarter,
  violent_crime_rate, property_crime_rate, sexual_crime_rate,
  total_crime_rate, vulnerability_tier (0/1/2/3),
  data_source (varchar), fetched_at

demographic_scores
  id, h3_index (FK), year,
  foreign_background_pct, median_income, employment_rate,
  social_assistance_pct, education_below_gymnasie_pct,
  rental_tenure_pct, population, migration_net,
  data_source, fetched_at

school_scores
  id, h3_index (FK), year,
  avg_merit_value, goal_achievement_pct,
  teacher_qualification_pct, school_count,
  school_quality_index (computed composite),
  data_source, fetched_at

debt_scores
  id, h3_index (FK), year,
  estimated_debt_rate, eviction_rate,
  debt_restructuring_rate, payment_order_rate,
  model_version (varchar),
  data_source, fetched_at

poi_scores
  id, h3_index (FK), fetched_at,
  negative_poi_count, positive_poi_count,
  negative_poi_density, positive_poi_density,
  poi_composite_score (computed)

transit_scores
  id, h3_index (FK), fetched_at,
  stop_count, nearest_center_minutes,
  transit_accessibility_index

── Composite output ────────────────────────────────────
neighborhood_scores
  id, h3_index (FK, unique per year), year,
  score (0-100), trend_1y, trend_3y, trend_5y,
  top_positive_factors (json), top_negative_factors (json),
  vulnerability_flag (boolean),
  computed_at, model_version

── Supporting ──────────────────────────────────────────
data_ingestion_logs
  id, source, started_at, completed_at, status,
  records_processed, error_message

kommuner
  id, kommun_code, name, lan_code, lan_name, population

schools
  id, school_unit_code, name, type, lat, lng, h3_index,
  kommun_code, latest_merit_value, latest_goal_pct

pois
  id, external_id (unique per source), source, category, subcategory,
  name, lat, lng, geom (PostGIS POINT), deso_code,
  tags (jsonb), metadata (jsonb), status, last_verified_at

poi_categories
  id, slug, name, indicator_slug, signal, osm_tags, google_types,
  catchment_km, is_active

-- Add to deso_areas:
urbanity_tier  -- 'urban', 'semi_urban', 'rural'

-- Add to indicators:
normalization_scope  -- 'national' or 'urbanity_stratified'
```

### 4.3 Laravel Directory Structure

```
app/
├── Console/Commands/
│   ├── Ingest/
│   │   ├── IngestBraCommand.php         # Download + parse BRÅ XLSX
│   │   ├── IngestScbCommand.php         # Fetch SCB PX-Web API
│   │   ├── IngestSkolverketCommand.php  # Fetch Skolverket API
│   │   ├── IngestKronofogdenCommand.php # Download + parse Kronofogden XLSX
│   │   ├── IngestPoiCommand.php         # Query Overpass API
│   │   ├── IngestVulnerableAreasCommand.php
│   │   └── IngestGtfsCommand.php
│   ├── Process/
│   │   ├── BuildDesoH3LookupCommand.php  # One-time: DeSO shapefile → H3 mapping
│   │   ├── MapCrimeToH3Command.php       # Police district rates → H3
│   │   ├── MapDemographicsToH3Command.php
│   │   ├── MapSchoolsToH3Command.php
│   │   ├── DisaggregateKronofogdenCommand.php  # Kommun → DeSO regression
│   │   ├── MapPoiToH3Command.php
│   │   └── ComputeScoresCommand.php      # Final composite score
│   └── Kernel.php                        # Schedule definitions
├── Models/
│   ├── DesoArea.php
│   ├── H3Cell.php
│   ├── DesoH3Mapping.php
│   ├── CrimeScore.php
│   ├── DemographicScore.php
│   ├── SchoolScore.php
│   ├── DebtScore.php
│   ├── PoiScore.php
│   ├── TransitScore.php
│   ├── NeighborhoodScore.php
│   └── School.php
├── Services/
│   ├── ScbApiService.php         # PX-Web API client
│   ├── SkolverketApiService.php  # Skolverket REST client
│   ├── OverpassService.php       # OSM Overpass queries
│   ├── H3Service.php             # Wrapper: calls h3-pg functions via raw SQL
│   ├── DisaggregationService.php # Kommun→DeSO regression logic
│   └── ScoringService.php        # Composite score computation
├── Http/Controllers/
│   ├── MapController.php         # Inertia page: map + hex layer
│   ├── ScoreController.php       # Inertia page: score detail per hex
│   ├── SearchController.php      # Address → geocode → H3 → score
│   └── Api/
│       └── ScoreApiController.php  # JSON API for external clients
└── Jobs/
    ├── ProcessBraData.php
    ├── ProcessScbData.php
    └── ...

resources/js/
├── Pages/
│   ├── Map.jsx            # Main map view with H3 hex layer
│   ├── ScoreDetail.jsx    # Per-hex breakdown view
│   └── Search.jsx         # Address search
├── Components/
│   ├── HexMap.jsx         # Deck.gl H3HexagonLayer wrapper
│   ├── ScoreCard.jsx      # Score display with trend arrows
│   ├── FactorBreakdown.jsx
│   └── SearchBar.jsx
└── app.jsx

scripts/
├── build_deso_h3_lookup.py   # GeoPandas: shapefile → H3 mapping CSV
├── disaggregate_kronofogden.py  # Regression model (sklearn)
└── requirements.txt          # geopandas, h3, shapely, scikit-learn, pyarrow
```

### 4.4 Pipeline Stages

#### Stage 1: Ingest (Laravel Scheduled Commands)

Each source has a dedicated Artisan command that downloads raw data:

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule): void
{
    // Quarterly
    $schedule->command('ingest:bra')->quarterly();

    // Annually (run in Q1 when new year data drops)
    $schedule->command('ingest:scb')->yearlyOn(2, 15);
    $schedule->command('ingest:kronofogden')->yearlyOn(2, 15);

    // Monthly
    $schedule->command('ingest:skolverket')->monthly();
    $schedule->command('ingest:poi')->monthly();
    $schedule->command('ingest:gtfs')->monthly();

    // After ingestion, process + score
    $schedule->command('process:map-all-to-h3')->monthlyOn(20);
    $schedule->command('process:compute-scores')->monthlyOn(25);
}
```

Fetch methods per source:

| Source | Method in Laravel |
|---|---|
| BRÅ | `Http::get()` to download XLSX → parse with PhpSpreadsheet |
| SCB | `Http::post()` to PX-Web API with JSON body → parse JSON-stat2 response |
| Skolverket | `Http::get()` to REST API → parse JSON |
| Kronofogden | `Http::get()` to download XLSX → parse with PhpSpreadsheet |
| Police areas | Manual download, store as GeoJSON (infrequent updates) |
| OSM POIs | `Http::post()` to Overpass API with QL query → parse response |
| GTFS | `Http::get()` to download GTFS zip → extract stop_times.txt, stops.txt |

#### Stage 2: Normalize + Store

Each ingestion command parses raw data into the corresponding Eloquent model. All geographic identifiers resolved to DeSO code, kommun code, or lat/lng.

#### Stage 3: Map to H3

Uses the pre-computed `deso_h3_mapping` table. For each data layer:

```php
// Example: mapping DeSO demographic data to H3
// In MapDemographicsToH3Command.php

$desoData = DemographicRaw::where('year', $year)->get();

foreach ($desoData as $row) {
    $mappings = DesoH3Mapping::where('deso_code', $row->deso_code)->get();

    foreach ($mappings as $mapping) {
        DemographicScore::updateOrCreate(
            ['h3_index' => $mapping->h3_index, 'year' => $year],
            [
                'foreign_background_pct' => $row->foreign_background_pct,  // rates: same for all hexes
                'median_income' => $row->median_income,
                'population' => $row->population * $mapping->area_weight,   // counts: weighted
                // ... etc
            ]
        );
    }
}
```

For Kronofogden (kommun → DeSO → H3), the `DisaggregateKronofogdenCommand` calls a Python script:

```php
// In DisaggregateKronofogdenCommand.php
Artisan::call('process:disaggregate-kronofogden', ['--year' => $year]);

// Which runs:
Process::run(['python3', base_path('scripts/disaggregate_kronofogden.py'), '--year', $year]);
```

The Python script reads kommun-level Kronofogden data + DeSO-level SCB variables from the database, trains the regression, writes estimated DeSO-level debt rates back to the database.

#### Stage 4: Compute Composite Score

```php
// In ComputeScoresCommand.php / ScoringService.php

$weights = [
    'violent_crime' => -0.15,
    'property_crime' => -0.10,
    'crime_trend' => -0.05,
    'vulnerability' => -0.10,
    'foreign_background' => -0.05,  // contextual weight
    'median_income' => 0.10,
    'income_trend' => 0.05,
    'employment' => 0.05,
    'school_quality' => 0.15,
    'debt_rate' => -0.05,
    'negative_poi' => -0.05,
    'positive_poi' => 0.05,
    'transit' => 0.05,
];

// For each H3 cell:
// 1. Fetch all layer scores
// 2. Normalize each to 0-1 (min-max across all hexes)
// 3. Weighted sum → raw score
// 4. Scale to 0-100
// 5. Compute trends vs prior years
// 6. Identify top contributing factors
```

#### Stage 5: Serve via Inertia

```php
// MapController.php
public function index(Request $request)
{
    $scores = NeighborhoodScore::query()
        ->where('year', now()->year)
        ->select('h3_index', 'score', 'trend_1y', 'vulnerability_flag')
        ->get();

    return Inertia::render('Map', [
        'scores' => $scores,
        'bounds' => [...], // Sweden bounding box
    ]);
}
```

```jsx
// Pages/Map.jsx
import { DeckGL } from '@deck.gl/react';
import { H3HexagonLayer } from '@deck.gl/geo-layers';

export default function Map({ scores }) {
    const layer = new H3HexagonLayer({
        id: 'h3-layer',
        data: scores,
        getHexagon: d => d.h3_index,
        getFillColor: d => scoreToColor(d.score),
        getElevation: d => d.score,
        extruded: false,
        pickable: true,
        onClick: (info) => router.visit(`/score/${info.object.h3_index}`),
    });

    return <DeckGL layers={[layer]} /* ... */ />;
}
```

---

## 5. Scoring Model

### 5.1 Feature Categories and Weights

| Category | Weight | Direction | Source |
|---|---|---|---|
| Crime rate (violent) | 15% | Negative | BRÅ |
| Crime rate (property) | 10% | Negative | BRÅ |
| Crime trend (3yr change) | 5% | Negative if rising | BRÅ |
| Police vulnerability tier | 10% | Negative (3=worst) | Polisen |
| % foreign background | 5% | Contextual | SCB DeSO |
| Median income | 10% | Positive | SCB DeSO |
| Income trend (3yr) | 5% | Positive if rising | SCB DeSO |
| Employment rate | 5% | Positive | SCB DeSO |
| School quality index | 15% | Positive | Skolverket |
| Estimated debt rate | 5% | Negative | Kronofogden + model |
| Negative POI density | 5% | Negative | OSM/Google |
| Positive POI density | 5% | Positive | OSM/Google |
| Transit accessibility | 5% | Positive | GTFS |

Weights should be recalibrated once historical property price data is integrated as a training signal.

### 5.2 Output Format

| Field | Type | Example |
|---|---|---|
| h3_index | String | `881f1d4a7ffffff` |
| score | Integer (0–100) | 62 |
| trend_1y | Float | +3.2 |
| trend_3y | Float | -1.8 |
| trend_5y | Float | +7.4 |
| top_positive_factors | JSON array | `["rising_income", "school_quality"]` |
| top_negative_factors | JSON array | `["crime_trend_up", "high_debt_rate"]` |
| vulnerability_flag | Boolean | false |
| data_freshness | Date | 2025-12-31 |

### 5.3 Client-Facing Labels

| Score Range | Label | Color |
|---|---|---|
| 80–100 | Strong Growth Area | Dark Green |
| 60–79 | Stable / Positive Outlook | Light Green |
| 40–59 | Mixed Signals | Yellow |
| 20–39 | Elevated Risk | Orange |
| 0–19 | High Risk / Declining | Red |

---

## 6. Key Implementation Notes

### 6.1 h3-pg PostgreSQL Extension

Install `h3-pg` in your PostgreSQL instance. This gives you H3 functions directly in SQL:

```sql
-- Point to hex
SELECT h3_lat_lng_to_cell(POINT(59.3293, 18.0686), 8);  -- Stockholm

-- Polygon to hexes (for DeSO boundary processing)
SELECT h3_polygon_to_cells(deso_geometry, 8) FROM deso_areas;

-- Neighbors (for school score inheritance)
SELECT h3_grid_ring('881f1d4a7ffffff', 1);

-- Parent resolution (for zoom levels)
SELECT h3_cell_to_parent('881f1d4a7ffffff', 7);
```

Laravel migration for the extension:

```php
// In a migration
DB::statement('CREATE EXTENSION IF NOT EXISTS h3');
DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
```

### 6.2 Python Scripts for Heavy Geo Processing

Two tasks are better handled in Python than PHP due to the geo ecosystem:

1. **DeSO boundary → H3 lookup table** (`scripts/build_deso_h3_lookup.py`): Uses GeoPandas to load the DeSO shapefile, h3-py to compute polygon-to-cells, and writes the mapping table to PostgreSQL via SQLAlchemy. This is a **one-time job** (rerun only if DeSO boundaries are revised by SCB).

2. **Kronofogden disaggregation** (`scripts/disaggregate_kronofogden.py`): Uses scikit-learn for the regression model. Reads kommun-level data from the DB, trains model, writes DeSO-level estimates back. Run **annually** when new Kronofogden data arrives.

Both scripts are called from Laravel Artisan commands via `Process::run()`.

```
scripts/requirements.txt:
geopandas==1.0+
h3==4.1+
shapely==2.0+
scikit-learn==1.5+
sqlalchemy==2.0+
psycopg2-binary
pyarrow
```

### 6.3 SCB PX-Web API Example (PHP)

```php
// In ScbApiService.php
public function fetchDesoIncome(int $year): array
{
    $response = Http::post('https://api.scb.se/OV0104/v1/doris/en/ssd/HE/HE0110/HE0110A/TabVX1DeSO', [
        'query' => [
            ['code' => 'Region', 'selection' => ['filter' => 'all', 'values' => ['*']]],
            ['code' => 'ContentsCode', 'selection' => ['filter' => 'item', 'values' => ['HE0110K3']]],
            ['code' => 'Tid', 'selection' => ['filter' => 'item', 'values' => [(string)$year]]],
        ],
        'response' => ['format' => 'json-stat2'],
    ]);

    return $this->parseJsonStat2($response->json());
}
```

### 6.4 Overpass API Example (PHP)

```php
// In OverpassService.php
public function fetchMosques(): array
{
    $query = '[out:json][timeout:120];
        area["ISO3166-1"="SE"]->.sweden;
        nwr["amenity"="place_of_worship"]["religion"="muslim"](area.sweden);
        out center;';

    $response = Http::asForm()->post('https://overpass-api.de/api/interpreter', [
        'data' => $query,
    ]);

    return collect($response->json()['elements'])->map(fn ($el) => [
        'lat' => $el['lat'] ?? $el['center']['lat'],
        'lng' => $el['lon'] ?? $el['center']['lon'],
        'name' => $el['tags']['name'] ?? 'Unknown',
        'type' => 'mosque',
    ])->all();
}
```

### 6.5 Data Refresh Schedule

| Source | Refresh | Laravel Schedule Method |
|---|---|---|
| SCB demographics | Annually (Q1) | `->yearlyOn(2, 15)` |
| BRÅ crime statistics | Quarterly + annually | `->quarterly()` |
| Skolverket school data | Monthly | `->monthly()` |
| Kronofogden debt stats | Annually | `->yearlyOn(2, 15)` |
| Police vulnerability list | When updated (~1-2 years) | Manual trigger |
| OSM POI data | Monthly | `->monthly()` |
| GTFS transit data | Monthly | `->monthly()` |
| Property prices (if integrated) | Monthly | `->monthly()` |

---

## 7. Data Source URL Reference

| Source | URL | Data Type |
|---|---|---|
| BRÅ statistics | https://bra.se/statistik/kriminalstatistik | Crime XLSX |
| BRÅ NTU survey | https://bra.se/statistik/statistik-utifran-brottstyper/nationella-trygghetsundersokningen.html | Survey |
| Police vulnerable areas | https://polisen.se/om-polisen/polisens-arbete/utsatta-omraden/ | Classification |
| SCB statistics database | https://www.statistikdatabasen.scb.se | Demographics |
| SCB PX-Web API | https://api.scb.se/OV0104/v1/doris/en/ssd/ | API |
| SCB REGINA map | https://regina.scb.se | DeSO lookup |
| Skolverket API | https://skolverket.se/om-oss/oppna-data/api-for-skolenhetsregistret | School data |
| Kronofogden open data | https://kronofogden.se/om-kronofogden/statistik/oppna-data-psidata | Debt XLSX |
| Kronofogden Statistikrobot | https://kronofogden.se/81975.html | Interactive |
| OSM Overpass API | https://overpass-api.de/api/interpreter | POI GeoJSON |
| Systembolaget stores | https://www.systembolaget.se/butiker-ombud/ | Locations |
| SST (faith communities) | https://www.myndighetensst.se/ | Organizations |
| Bolagsverket | https://www.bolagsverket.se/ | Company register |
| Uber H3 docs | https://h3geo.org/docs/ | Spatial index docs |
| h3-pg (PostgreSQL) | https://github.com/zachasme/h3-pg | DB extension |
| h3-py (Python) | https://pypi.org/project/h3/ | Python library |
| Deck.gl H3 layer | https://deck.gl/docs/api-reference/geo-layers/h3-hexagon-layer | Map rendering |

---

*— End of Document —*