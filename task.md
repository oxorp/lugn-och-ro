# Updates Required for Existing Project Documents

These changes align the existing documentation with the decisions made in this session: removing the public-data-only constraint, adding urbanity stratification, and establishing the POI system.

---

## 1. project-context.md

### Section: Project Overview (line 5)

**Current:**
> Building a Swedish real estate prediction platform that scores neighborhoods (DeSO areas) using public government data.

**Change to:**
> Building a Swedish real estate prediction platform that scores neighborhoods (DeSO areas) using government statistics, commercial data, and open-source platforms.

### Section: Legal Constraints (line 17)

**Current:**
> **Our design rule:** All data inputs are aggregate-level public statistics. No individual-level personal data.

**Change to:**
> **Our design rule:** All data inputs are aggregate-level statistics — no individual-level personal data is stored or processed. Data sources include government agencies (SCB, BRÅ, Skolverket, Kronofogden), open platforms (OpenStreetMap), and commercial APIs (Google Places). The legal constraint is on individual-level data, not on the source type.

### Section: Best practices established (after line 33)

**Add:**
```
- Amenity/access indicators use urbanity-stratified normalization (rank within urban/semi-urban/rural tier)
- Socioeconomic indicators use national normalization (rank against all DeSOs)
- POI data always stored as per-capita density, never raw counts
- Zero POI count is data (store as 0), NULL means "not measured"
- Catchment radius (not DeSO boundary) defines "access" for point-based features
```

### Section: Database Schema — Core Tables (add after school_statistics)

**Add:**
```
pois
  id, external_id, source, category, subcategory, name,
  lat, lng, deso_code (indexed), municipality_code,
  tags (jsonb), metadata (jsonb), status, last_verified_at,
  geom (PostGIS POINT 4326)
  UNIQUE(source, external_id)

poi_categories
  id, slug (unique), name, indicator_slug, signal,
  osm_tags (json), google_types (json), catchment_km, is_active
```

### Section: Database Schema — deso_areas

**Add column:**
```
urbanity_tier VARCHAR(20)  -- 'urban', 'semi_urban', 'rural'
```

### Section: Database Schema — indicators table

**Add column:**
```
normalization_scope VARCHAR(30) DEFAULT 'national'
  -- 'national' = rank against all DeSOs
  -- 'urbanity_stratified' = rank within urban/semi-urban/rural tier
```

### Section: Scoring Model — Weight Budget

**Update to:**

| Category | Weight | Source |
|---|---|---|
| Income | 0.18 | SCB |
| Employment | 0.08 | SCB |
| Education (demographics) | 0.08 | SCB |
| Education (school quality) | 0.22 | Skolverket |
| Amenities — positive | 0.11 | OSM, Google Places |
| Amenities — negative | 0.04 | OSM, Google Places |
| Transport | 0.04 | GTFS, OSM |
| **Unallocated** | **0.25** | Crime (BRÅ), debt (Kronofogden) |

### Section: Data Sources — add new subsection after Skolverket

**Add:**

#### 3. Points of Interest (OSM + Google Places)
- **Overpass API (OSM):** `https://overpass-api.de/api/interpreter` — free, good Sweden coverage
- **Google Places API:** Paid ($32/1,000 requests), better for specialty/commercial POIs
- **Categories:** Grocery, healthcare, restaurant, fitness, transit stops (positive); gambling, pawn shops, fast food clusters (negative)
- **Pattern:** Point data → DeSO assignment via ST_Contains → catchment-based per-capita density → indicator_values with urbanity-stratified normalization

### Section: Architecture — Laravel Directory Structure

**Add to Commands:**
```
app/Console/Commands/
  Ingest/IngestPois.php
  Process/AssignPoiDeso.php
  Process/AggregatePoiIndicators.php
  Process/ClassifyDesoUrbanity.php
```

**Add to Services:**
```
app/Services/
  OverpassService.php
  GooglePlacesService.php (future)
```

### Section: API Endpoints

**Add:**
```
GET  /api/deso/{code}/pois      → POIs in/near a specific DeSO
```

### Section: Task History

**Add:**
```
### Task 4 (Queued): Data Quality & Governance Framework
- Validation rules per indicator (range, completeness, distribution, change rate)
- Sentinel areas (Danderyd must score high, Rinkeby must score low)
- Score versioning with publish/rollback
- Anomaly detection (drift between score versions)
- Data freshness tracking
- Pipeline orchestration command with validation gates
- Admin data quality dashboard

### Task 5 (Queued): Urbanity Classification & Stratified Normalization
- Classify all DeSOs as urban/semi-urban/rural
- Add normalization_scope to indicators table
- Refactor NormalizationService for per-tier percentile ranking
- Prerequisite for POI system

### Task 6 (Queued): POI Ingestion System
- Generic pois table for all point-of-interest data
- Overpass API (OSM) ingestion
- DeSO spatial assignment
- Catchment-based per-capita density aggregation
- Positive indicators: grocery, healthcare, restaurant, fitness, transit
- Negative indicators: gambling, pawn shops, fast food
- Urbanity-stratified normalization for all POI indicators

### Task 7 (Queued): H3 Hexagonal Grid & Spatial Smoothing
- h3-pg extension
- DeSO→H3 mapping table
- Score projection to hexagons
- Spatial smoothing (configurable intensity)
- Multi-resolution viewport-based rendering
- Layer toggle (hexagons/DeSO polygons)
```

---

## 2. data_pipeline_specification.md

### Section 1.3: Legal Constraints — Design Rules (lines 35-39)

**Current:**
```
- All data inputs must be **aggregate-level public statistics** from government sources
- The scoring model uses only data from SCB, BRÅ, Kronofogden, Skolverket, and Polisen
```

**Change to:**
```
- All data inputs must be **aggregate-level statistics** — no individual-level personal data
- The scoring model uses data from government agencies (SCB, BRÅ, Kronofogden, Skolverket, Polisen), open data platforms (OpenStreetMap), and commercial APIs (Google Places) as needed
- The constraint is on individual-level data, not on source type — any aggregate data source that improves prediction quality is valid
```

### Section 3.5: Points of Interest

**This section already exists** (lines ~287-340) and already describes POI sources, OSM tags, and Google Places. No major changes needed, but add a note about urbanity-stratified normalization:

**Add after section 3.5.4 (Pipeline: POI → H3):**

> **Normalization:** POI indicators use urbanity-stratified percentile ranking. Rural DeSOs are ranked against other rural DeSOs, urban against urban. This prevents rural areas from being unfairly penalized for lower absolute amenity counts that are appropriate for their context.

### Section 4.2: Database Schema

**Add to schema:**
```
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

---

## 3. CLAUDE.md

### Add to Best Practices section:

```
## Normalization Rules
- Socioeconomic indicators (income, employment, education, crime, debt): **national** percentile rank
- Amenity/access indicators (POI density, transit, healthcare): **urbanity-stratified** percentile rank
- The scoring engine doesn't care about normalization scope — it reads normalized_value identically
- Rule of thumb: if it measures physical access → stratified. If it measures a rate or outcome → national.

## POI System
- All POI data goes in the generic `pois` table regardless of source or category
- Adding a new POI type = new row in `poi_categories` + a scrape run
- Always compute per-capita density, never raw counts
- Use catchment radius (not DeSO boundary) for access metrics
- Zero is data (store as 0.0), NULL means unmeasured
- OSM first, Google Places for gaps

## Data Source Policy
- No restriction on source type (government, commercial, open-source all valid)
- Restriction is on individual-level data: aggregate statistics only
- GDPR Article 10 constraint specifically on criminal conviction data linked to individuals
```

---

