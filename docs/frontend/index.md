# Frontend

> React 19 + Inertia.js v2 application with OpenLayers heatmap and pin-drop scoring.

## Architecture

The frontend is a server-side routed SPA using Inertia.js. Pages are React components that receive props from Laravel controllers. The primary interaction is **pin-drop scoring**: users click the map or search an address, a pin drops, and the sidebar shows a blended area + proximity score with full detail breakdown.

```
resources/js/
├── pages/
│   ├── explore/                  # Main explore experience (refactored from map.tsx)
│   │   ├── components/
│   │   │   ├── active-sidebar.tsx    # Sidebar when location is selected
│   │   │   ├── score-card.tsx        # Score display with history
│   │   │   ├── indicator-bar.tsx     # Individual indicator row with trend
│   │   │   ├── sparkline.tsx         # SVG mini-chart for historical trends
│   │   │   └── trend-arrow.tsx       # Directional change arrow (↑↗→↘↓)
│   │   └── types.ts                  # TypeScript types for explore data
│   ├── admin/
│   │   ├── indicators.tsx            # Indicator weight management
│   │   ├── penalties.tsx             # Score penalty configuration
│   │   ├── data-completeness.tsx     # Data coverage heatmap matrix
│   │   ├── data-quality.tsx          # Score versions & validation
│   │   ├── pipeline.tsx              # Pipeline status dashboard
│   │   └── pipeline-source.tsx       # Per-source pipeline detail
│   ├── auth/                         # Authentication pages
│   └── settings/                     # User settings
├── components/
│   ├── deso-map.tsx                  # OpenLayers heatmap component
│   ├── map-search.tsx                # Geocoding search bar
│   ├── info-tooltip.tsx              # Indicator tooltips
│   └── ui/                           # shadcn/ui primitives
├── hooks/
│   ├── use-translation.ts
│   └── use-poi-layer.ts
├── layouts/
│   ├── map-layout.tsx                # Full-screen map layout
│   └── admin-layout.tsx              # Admin tab navigation
├── lib/
│   └── poi-icons.ts                  # POI icon SVG generation
└── services/
    └── geocoding.ts
```

## Key Technologies

| Technology | Version | Purpose |
|---|---|---|
| React | 19 | UI framework |
| Inertia.js | v2 | Server-side routing without API layer |
| TypeScript | strict | Type safety (`.tsx` only, no `.jsx`) |
| OpenLayers | latest | Map rendering (not Mapbox/Leaflet/Deck.gl) |
| Tailwind CSS | v4 | Utility-first styling |
| shadcn/ui | latest | Component library |
| FontAwesome 7 Pro | latest | Icon library (solid + regular) |
| Sonner | latest | Toast notifications |

## Core Interaction: Pin-Drop Scoring

1. User clicks map or searches an address
2. Pin drops at coordinate, 3 km radius circle drawn
3. `GET /api/location/{lat},{lng}` fetches blended score + nearby data
4. Sidebar shows: blended score, proximity factors, indicators, schools, POIs
5. URL updates to `/explore/{lat},{lng}` for shareable links

## Historical Trends (New)

For paid-tier users, the sidebar now shows historical data:

- **Sparklines** (`sparkline.tsx`) — SVG inline trend charts showing 5–6 years of percentile data per indicator. Color-coded: green (rising), red (falling), gray (flat).
- **Trend Arrows** (`trend-arrow.tsx`) — 1-year percentage point change with directional arrow and color: ↑ (+3), ↗ (+1–2.9), → (stable), ↘ (-1–2.9), ↓ (-3+). Direction-aware for negative indicators (lower crime = green arrow).
- **Score History** — Composite score sparkline on the score card, showing multi-year trajectory.

Historical data flows from `indicator_values` (multi-year) through the `LocationController`, which returns `trend` objects with `years`, `percentiles`, `raw_values`, and `change_1y`/`change_3y`/`change_5y`.

## Admin Pages

- **Indicators** (`/admin/indicators`) — Weight, direction, and active status management
- **Penalties** (`/admin/penalties`) — Score penalty configuration with impact preview (affected DeSOs, population, score simulation)
- **Data Completeness** (`/admin/data-completeness`) — Color-coded heatmap matrix: indicator × year coverage across all 6,160 DeSOs
- **Data Quality** (`/admin/data-quality`) — Score versions, validation checks, publish/rollback
- **Pipeline** (`/admin/pipeline`) — Ingestion pipeline status per source

## Sections

- [Map Rendering](/frontend/map-rendering) — OpenLayers heatmap tiles with pin-drop interaction
- [Sidebar](/frontend/sidebar) — Location score, proximity factors, and detail panels
- [School Markers](/frontend/school-markers) — School visualization on the map
- [POI Display](/frontend/poi-display) — Point of interest markers with category icons
- [Admin Dashboard](/frontend/admin-dashboard) — Indicator and pipeline management
- [Components](/frontend/components) — Reusable component inventory

## Related

- [Architecture Stack](/architecture/stack)
- [API Overview](/api/)
- [Location Lookup API](/api/location-lookup)
