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
- Weighted composite scoring (0-100) with direction-adjusted normalization
- National and urbanity-stratified percentile rank normalization
- Multi-tenant support with per-tenant indicator weights
- Trend computation (rising/falling/stable) with methodology break detection

### Frontend
- Interactive OpenLayers map with dual DeSO polygon and H3 hexagon layers
- H3 viewport-based loading with zoom-to-resolution mapping
- Spatial smoothing (configurable: None/Light/Medium/Strong)
- Area comparison mode with shareable URLs
- School markers with merit-based coloring
- POI layer with clustering and category controls
- Six-tier data access with progressive disclosure

### Infrastructure
- Docker Compose with PostGIS + H3, Redis, Horizon
- 32 custom Artisan commands
- Full pipeline automation via `pipeline:run`
- Admin dashboard for indicator weight management
