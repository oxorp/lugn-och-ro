# Changelog

> Development milestones and notable changes.

## 2026-02

### Documentation
- Comprehensive VitePress documentation site covering all 10 sections
- Architecture, data pipeline, indicators, data sources, methodology, API, operations, frontend, business

### Data Pipeline
- Full pipeline with 7 data sources: SCB, Skolverket, BRA, NTU, Polisen vulnerability areas, Kronofogden/Kolada, OSM POIs
- 27 active indicators with calibrated weights summing to 1.00
- Disaggregation models for crime (kommun→DeSO) and financial distress (kommun→DeSO)
- Score versioning with publish/rollback lifecycle
- Data quality validation with sentinel area checks and drift detection

### Scoring Engine
- **Blended scoring**: area score (70%) + proximity score (30%) per address
- `ProximityScoreService` with 6 real-time distance-based factors (school, green space, transit, grocery, negative/positive POIs)
- **Safety-modulated distance decay**: `SafetyScoreService` (0.0–1.0) with per-category sensitivity (0.0–1.5)
- **Urbanity-tiered radii**: scoring, query, and display radii adapt to urban/semi-urban/rural via `config/proximity.php`
- School factor: up to 10 schools, rich per-school details, fallback query for nearest school
- Weighted composite scoring (0-100) with direction-adjusted normalization
- National and urbanity-stratified percentile rank normalization
- Red-to-green color scale defined in `config/score_colors.php`
- Multi-tenant support with per-tenant indicator weights
- Trend computation (rising/falling/stable) with methodology break detection
- Weight rebalancing via `CategoryWeightRebalanceSeeder` with category budgets (25/20/15/10/30%)

### Indicator Categories
- Reorganized from 11 fragmented categories → 6 clean: safety, economy, education, environment, proximity, contextual
- `config/indicator_categories.php` defines category metadata (label, icon, member indicators)
- 8 free preview indicators (2 per display category) for public tier

### Frontend
- **Pin-drop scoring**: click map or search → blended score with full breakdown
- Refactored monolithic `map.tsx` → modular `pages/explore/` directory (MapPage, ActiveSidebar, DefaultSidebar, ScoreCard, IndicatorBar, ProximityFactorRow)
- **Free tier preview**: `LockedPreviewContent` with 8 real indicator values, category sections, school skeletons, purchase CTA
- **Sticky unlock bar**: Appears on scroll with "Lås upp fullständig rapport" (79 kr)
- Pre-rendered heatmap tile overlay (Gaussian-blurred H3 scores)
- Country mask (dims areas outside Sweden) with dashed border
- Three basemap options: CARTO Clean, OSM Detailed, Esri Satellite
- `PercentileBadge` and `PercentileBar` shared components
- Score colors library (`lib/score-colors.ts`) matching backend config
- Shareable explore URLs (`/explore/{lat},{lng}`)

### Purchase & Reports
- **One-time report purchase** (79 SEK) via Stripe Checkout for any address
- Guest purchases (email only) with automatic claiming on account creation
- `PurchaseController`, `StripeWebhookController`, `ReportController`, `MyReportsController`
- Report model with UUID, Stripe session tracking, view counting
- Guest report access via signed URLs (24h validity)
- `purchase:cleanup` command for expired checkout sessions

### Authentication
- **Google OAuth** via `SocialAuthController` (Laravel Socialite)
- Guest report claiming on login via `ClaimGuestReports` listener
- Registration page with email/password flow

### API
- `GET /api/location/{lat},{lng}` — primary per-address lookup with `preview` object for public tier
- `GET /tiles/{year}/{z}/{x}/{y}.png` — pre-rendered heatmap tile serving
- Purchase and report routes for Stripe checkout flow

### Infrastructure
- Docker Compose with PostGIS + H3, Redis, Horizon
- 34 custom Artisan commands (including `generate:heatmap-tiles`, `purchase:cleanup`)
- Python heatmap tile generator (`scripts/generate_heatmap_tiles.py`)
- Full pipeline automation via `pipeline:run`
- Admin dashboard for indicator weight management
- Stripe webhook integration (CSRF excluded)

---

## 2026-02 (Late February Update)

### Score Penalty System
- **Replaced `vulnerability_flag` indicator** with post-calculation penalty deductions
- Two penalty tiers: Särskilt utsatt (-15 pts) and Utsatt (-8 pts) based on police vulnerability area classification
- Penalty values configurable at `/admin/penalties` with impact preview (affected DeSOs, population)
- Supports both `absolute` and `percentage` penalty types
- Audit trail: `raw_score_before_penalties` and `penalties_applied` JSON stored on composite scores
- Weight from old indicator redistributed to crime and safety indicators

### Historical Data & DeSO Crosswalk
- **DeSO 2018 ↔ 2025 spatial crosswalk** — area-weighted mapping of 5,984 old codes to 6,160 new codes
- `import:deso-2018-boundaries` — downloads old boundary polygons from SCB WFS (~50 MB)
- `build:deso-crosswalk` — PostGIS spatial overlap computation with mapping type classification (1:1 / split / merge / partial)
- `CrosswalkService` — handles rate vs count redistribution logic
- `ingest:scb-historical --from=2019 --to=2023` — fetches and maps historical SCB data through crosswalk
- `ingest:bra-historical` — multi-year BRÅ crime ingestion from SOL Excel export
- Multi-year support added to `ingest:kronofogden`, `ingest:ntu`, `ingest:skolverket-stats`
- Historical data availability: SCB 2019–2024, Skolverket 2020/21–2024/25, BRÅ 2019–2024, Kolada 2019–2025, NTU 2019–2025

### GTFS Transit Data
- **Replaced OSM transit stops** with authoritative GTFS Sverige 2 feed (Samtrafiken)
- `ingest:gtfs` — 9-step pipeline: download → import ~47K stops → Python frequency computation → DeSO assignment → POI insertion
- `scripts/compute_gtfs_frequencies.py` — streams stop_times.txt in 500K-row chunks, classifies modes and time buckets
- `transit_stops` and `transit_stop_frequencies` tables with per-stop departure counts by mode and time bucket
- `ProximityScoreService::scoreTransit()` upgraded: mode weighting (rail 1.5x, tram 1.2x), log-scaled frequency bonus
- High-value stops inserted as tiered POIs: rail stations, tram stops, high-frequency bus stops
- Requires `TRAFIKLAB_GTFS_KEY` and Python 3 in Docker container

### Historical Trend Visualization
- **Sparkline component** — SVG inline charts (200×40px) showing 5–6 years of percentile data per indicator
- **Trend arrow component** — directional 1-year change arrows (↑↗→↘↓) with color coding, direction-aware for negative indicators
- **Score history** — composite score sparkline on the score card
- Location API now returns `trend` object per indicator: `{years, percentiles, raw_values, change_1y, change_3y, change_5y}`
- Score history: `{years, scores}` for composite score trajectory

### Admin Dashboard Expansion
- **Data Completeness page** (`/admin/data-completeness`) — color-coded heatmap matrix showing indicator × year coverage across all DeSOs
- **Penalties page** (`/admin/penalties`) — configure penalty values, types, and map styling with score simulation preview
- **Vulnerability Areas API** (`/api/vulnerability-areas`) — GeoJSON endpoint with penalty metadata for map overlay (24h cache)

### Data Validation
- `validate:indicators` command — sanity checks against known reference points (kommun averages, national medians)
- `config/data_sanity_checks.php` — reference values for well-known municipalities (Danderyd, Lund, Filipstad, Lomma)

### Isochrone-Based Proximity Scoring
- **Replaced radius circles with isochrone overlays** — walking/driving time polygons via Valhalla routing engine
- `IsochroneService` — fetches multi-contour polygons (5/10/15 min) from Valhalla, caches by ~100m grid cell
- `ProximityScoreService` upgraded: queries POIs inside isochrone polygon, gets actual travel times via Valhalla matrix API
- Display contours: 5/10/15 min (urban/semi-urban pedestrian), 5/10/20 min (rural auto)
- Travel mode per urbanity tier: **pedestrian** (urban, semi-urban), **auto** (rural)
- Sidebar shows travel times per amenity (e.g., "8 min promenad") instead of distances
- Graceful fallback to radius-based scoring when Valhalla unavailable
- Grid-cell caching (~100m, 3600s TTL) for both isochrone and proximity results
- **Valhalla Docker service** added (`ghcr.io/gis-ops/docker-valhalla`) with Sweden OSM data
- `.env` config: `VALHALLA_URL`, `ISOCHRONE_ENABLED`

### Report Generation System
- **`ReportGenerationService`** — snapshots all data at purchase time into 11 categories: indicators, scores, verdicts, schools, proximity, isochrone, map, strengths/weaknesses, outlook
- **`VerdictService`** — generates Swedish-language analysis for 4 categories (safety, economy, education, environment) with A–E grades, trend direction, and narrative text
- Report show page (`/reports/{uuid}`) — full-page printable report with hero score, map, verdict grid, indicator breakdown, schools, strengths/weaknesses, and outlook
- 24 new snapshot columns on `reports` table (JSON + decimal) — immutable data frozen at generation time
- Admin report generation (`POST /admin/reports/generate`) — bypasses Stripe for testing/admin use
- Isochrone data stored per report for offline viewing

### Map & UI Improvements
- Geography indexes on DeSO areas for faster spatial queries
- Map refactoring for improved performance
- Improved contrast on admin data completeness page
