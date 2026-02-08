# Frontend

> React 19 + Inertia.js v2 application with OpenLayers heatmap and pin-drop scoring.

## Architecture

The frontend is a server-side routed SPA using Inertia.js. Pages are React components that receive props from Laravel controllers. The primary interaction is **pin-drop scoring**: users click the map or search an address, a pin drops, and the sidebar shows a blended area + proximity score with full detail breakdown.

```
resources/js/
├── pages/              # Inertia page components
│   ├── map.tsx         # Main map + sidebar (~700 lines)
│   ├── dashboard.tsx   # User dashboard
│   ├── methodology.tsx # Public methodology page
│   ├── admin/          # Admin pages
│   │   ├── indicators.tsx
│   │   ├── data-quality.tsx
│   │   ├── pipeline.tsx
│   │   └── pipeline-source.tsx
│   ├── auth/           # Authentication pages
│   └── settings/       # User settings
├── components/         # Reusable components
│   ├── deso-map.tsx    # OpenLayers heatmap component
│   ├── map-search.tsx  # Geocoding search bar
│   ├── info-tooltip.tsx# Indicator tooltips
│   └── ui/             # shadcn/ui primitives
├── hooks/              # Custom React hooks
│   ├── use-translation.ts
│   └── use-poi-layer.ts
├── layouts/            # Layout components
│   ├── map-layout.tsx  # Full-screen map layout
│   └── admin-layout.tsx# Admin tab navigation
├── lib/                # Utilities
│   └── poi-icons.ts    # POI icon SVG generation
└── services/           # Client-side services
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
| Lucide React | latest | Icon library (map markers, factor icons) |
| Sonner | latest | Toast notifications |

## Core Interaction: Pin-Drop Scoring

1. User clicks map or searches an address
2. Pin drops at coordinate, 3 km radius circle drawn
3. `GET /api/location/{lat},{lng}` fetches blended score + nearby data
4. Sidebar shows: blended score, proximity factors, indicators, schools, POIs
5. URL updates to `/explore/{lat},{lng}` for shareable links

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
