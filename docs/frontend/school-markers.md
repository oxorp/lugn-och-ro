# School Markers

> School visualization on the map with merit-based coloring and Lucide icon markers.

## When Schools Appear

Schools are **not** shown by default. They appear when a user drops a pin (clicks the map or searches an address). The location API returns schools within **1.5 km** of the pin coordinate, and they're rendered on the map via the `setSchoolMarkers()` imperative handle.

## Marker Style

Schools use Lucide `graduation-cap` SVG icons rendered as OpenLayers `Icon` styles. Each marker is a colored pin with the graduation cap icon:

```typescript
const dataUrl = getPoiMarkerDataUrl('graduation-cap', fillColor, iconSize);
```

### Icon Size by Zoom

| Zoom | Icon Size |
|---|---|
| < 14 | 20 px |
| 14 | 24 px |
| 15+ | 28 px |

### Color by Meritvärde

| Merit Range | Color | Meaning |
|---|---|---|
| > 230 | Green (`#22c55e`) | High performance |
| 200–230 | Yellow (`#eab308`) | Average |
| < 200 | Orange (`#f97316`) | Below average |
| No data | Gray (`#94a3b8`) | Statistics unavailable |

If the SVG icon cannot be generated, a fallback colored circle (radius 6) is used.

## Data Source

Schools come from the **location lookup API** response, not from a separate endpoint:

```
GET /api/location/{lat},{lng}
→ response.schools[]
```

Each school entry includes:
- `name`, `type`, `operator_type`
- `merit_value`, `goal_achievement`, `teacher_certification`
- `student_count`
- `lat`, `lng`, `distance_m` (from pin)

## Interactions

### Hover
Shows tooltip overlay with:
- School name (bold)
- Meritvärde value (if available)

### Click
No click handler — school details are shown in the sidebar instead.

## Sidebar Display

In the sidebar, schools are listed as cards sorted by distance:

- School name
- Type (Grundskola) and operator (Kommunal/Fristående)
- Distance from pin
- Merit value (if available)

Schools section is **tier-gated** — only visible to paid users (tier >= 1).

## Related

- [Map Rendering](/frontend/map-rendering)
- [Location Lookup API](/api/location-lookup)
- [School Quality Indicators](/indicators/school-quality)
