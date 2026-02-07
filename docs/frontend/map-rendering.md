# Map Rendering

> OpenLayers map with dual DeSO polygon and H3 hexagon layers.

## Component: `DesoMap`

**File**: `resources/js/components/deso-map.tsx`

A `forwardRef` component exposing an imperative handle (`DesoMapHandle`) for parent control.

## Layer Stack

| Layer | Type | Z-Index | Default |
|---|---|---|---|
| OSM Tiles | `TileLayer` | 0 | Visible |
| DeSO Polygons | `VectorLayer` | 1 | Hidden |
| H3 Hexagons | `VectorLayer` | 1 | Visible |
| School Markers | `VectorLayer` | 10 | Visible |
| Search Marker | `VectorLayer` | 50 | Visible |
| Location Marker | `VectorLayer` | 90 | Visible |
| Compare Pins | `VectorLayer` | 100 | Visible |

## Layer Modes

Users toggle between two visualization modes via the `LayerControl` widget:

- **Hexagons** (default) — H3 hexagonal grid, loaded per viewport
- **DeSO** — Statistical area polygons from static GeoJSON

### H3 Viewport Loading

When in hexagon mode, the map fetches H3 data on every `moveend` event (debounced 300ms):

```
GET /api/h3/viewport?bbox={minLng},{minLat},{maxLng},{maxLat}&zoom={zoom}&year=2024&smoothed=true
```

Previous requests are cancelled via `AbortController`. The response contains `h3_index`, `score`, and `primary_deso_code` per hex.

Client-side hex boundary computation uses `h3-js`:
```typescript
const boundary = cellToBoundary(h3Index, true); // GeoJSON [lng, lat] format
```

### DeSO Loading

On initial load, the map fetches two resources in parallel:
1. `/data/deso.geojson` — Static file with all 6,160 DeSO boundaries
2. `/api/deso/scores?year=2024` — Score data keyed by `deso_code`

## Color Scale

Five-stop gradient from purple (0) to green (100):

| Score | Color | Hex |
|---|---|---|
| 0 | Purple | `#4a0072` |
| 25 | Red-purple | `#9c1d6e` |
| 50 | Yellow | `#f0c040` |
| 75 | Light green | `#6abf4b` |
| 100 | Deep green | `#1a7a2e` |

Polygon fill alpha: 180/255 (~70%). Hover increases to 85%.

Areas without scores show gray with dashed borders.

## Interactions

### Hover
- **Polygon**: Brighter fill + thicker stroke
- **School marker**: Tooltip with name and meritvärde
- **POI cluster**: Tooltip with count and sentiment breakdown
- **H3 hex**: Tooltip showing score value

### Click
- **Polygon/Hex**: Selects the area, opens sidebar with score details
- **H3 without DeSO**: Zooms in to reveal finer resolution
- **School marker**: Opens school detail panel
- **POI cluster**: Zooms to cluster extent (or shows single POI detail)
- **Compare mode**: Places A/B pins for comparison

### Compare Mode
When active, cursor changes to crosshair. Clicks place A/B pins with a dashed connection line. Pins are blue (A) and amber (B) circles with letter labels.

## Imperative Handle

The parent `map.tsx` page controls the map via ref:

| Method | Purpose |
|---|---|
| `updateSize()` | Recalculate map size after layout change |
| `setSchoolMarkers(schools)` | Render school pins on the map |
| `clearSchoolMarkers()` | Remove all school pins |
| `placeSearchMarker(lat, lng)` | Drop a search result pin |
| `zoomToPoint(lat, lng, zoom)` | Animated zoom to location |
| `zoomToExtent(w, s, e, n)` | Fit view to bounding box |
| `selectDesoByCode(code)` | Programmatically select a DeSO |
| `clearSelection()` | Deselect current area |
| `placeComparePin(id, lat, lng)` | Place A or B comparison pin |
| `placeLocationMarker(lat, lng, accuracy)` | Show user location with pulse animation |
| `fitToPoints(points)` | Fit view to array of points |

## UI Overlays

- **Score Legend**: Bottom-left gradient bar (purple to green)
- **Layer Control**: Top-right radio buttons (Hexagons / Statistical Areas) + smoothing toggle
- **Zoom Debug**: Bottom-right badge showing current zoom level

## Related

- [H3 Endpoints](/api/h3-endpoints)
- [Spatial Framework](/architecture/spatial-framework)
- [Sidebar](/frontend/sidebar)
