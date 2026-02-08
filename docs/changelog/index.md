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
- Weighted composite scoring (0-100) with direction-adjusted normalization
- National and urbanity-stratified percentile rank normalization
- Multi-tenant support with per-tenant indicator weights
- Trend computation (rising/falling/stable) with methodology break detection
- Weight rebalancing via `ProximityIndicatorSeeder` (area × 0.753, proximity = 0.30)

### Frontend
- **Pin-drop scoring**: click map or search → blended score with full breakdown
- Pre-rendered heatmap tile overlay (Gaussian-blurred H3 scores)
- Country mask (dims areas outside Sweden) with dashed border
- Three basemap options: CARTO Clean, OSM Detailed, Esri Satellite
- Proximity factor bars in sidebar (school, transit, grocery, green space, negative/positive POIs)
- Per-address school markers (1.5 km) and POI markers (3 km) with Lucide SVG icons
- Shareable explore URLs (`/explore/{lat},{lng}`)
- Six-tier data access with progressive disclosure

### API
- `GET /api/location/{lat},{lng}` — primary per-address lookup endpoint
- `GET /tiles/{year}/{z}/{x}/{y}.png` — pre-rendered heatmap tile serving

### Infrastructure
- Docker Compose with PostGIS + H3, Redis, Horizon
- 33 custom Artisan commands (including `generate:heatmap-tiles`)
- Python heatmap tile generator (`scripts/generate_heatmap_tiles.py`)
- Full pipeline automation via `pipeline:run`
- Admin dashboard for indicator weight management
