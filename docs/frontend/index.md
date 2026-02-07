# Frontend

> React 19 + Inertia.js v2 application with OpenLayers map.

## Architecture

The frontend is a server-side routed SPA using Inertia.js. Pages are React components that receive props from Laravel controllers.

```
resources/js/
├── pages/              # Inertia page components
│   ├── map.tsx         # Main map interface (~2800 lines)
│   ├── dashboard.tsx   # User dashboard
│   ├── methodology.tsx # Public methodology page
│   ├── welcome.tsx     # Landing page
│   ├── admin/          # Admin pages
│   │   ├── indicators.tsx
│   │   ├── data-quality.tsx
│   │   ├── pipeline.tsx
│   │   └── pipeline-source.tsx
│   ├── auth/           # Authentication pages
│   └── settings/       # User settings
├── components/         # Reusable components
│   ├── deso-map.tsx    # OpenLayers map component (~1200 lines)
│   ├── comparison-sidebar.tsx
│   ├── poi-controls.tsx
│   ├── map-search.tsx
│   └── ui/             # shadcn/ui primitives
├── hooks/              # Custom React hooks
│   ├── use-translation.ts
│   └── use-poi-layer.ts
├── layouts/            # Layout components
│   └── map-layout.tsx
├── lib/                # Utilities
│   └── poi-config.ts
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
| h3-js | latest | Client-side H3 hex boundary computation |
| Lucide React | latest | Icon library |
| Sonner | latest | Toast notifications |

## Sections

- [Map Rendering](/frontend/map-rendering) — OpenLayers map with DeSO and H3 layers
- [Sidebar](/frontend/sidebar) — Score display, indicators, and area comparison
- [School Markers](/frontend/school-markers) — School visualization on the map
- [POI Display](/frontend/poi-display) — Point of interest layer with clustering
- [Admin Dashboard](/frontend/admin-dashboard) — Indicator and pipeline management
- [Components](/frontend/components) — Reusable component inventory

## Related

- [Architecture Stack](/architecture/stack)
- [API Overview](/api/)
