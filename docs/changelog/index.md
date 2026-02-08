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
