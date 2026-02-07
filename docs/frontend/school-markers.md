# School Markers

> School visualization on the map with merit-based coloring.

## Marker Styles

Schools are rendered as vector features on a dedicated layer (z-index 10).

### Shape by School Type

| School Type | Shape | Size |
|---|---|---|
| Grundskola | Circle | Radius 7 |
| Gymnasieskola (only) | Diamond (rotated square) | Radius 8 |
| Other | Small circle | Radius 4, muted color |

### Color by Meritvärde

| Merit Range | Color | Meaning |
|---|---|---|
| > 230 | Green (`rgba(34, 197, 94, 0.9)`) | High performance |
| 200-230 | Yellow (`rgba(234, 179, 8, 0.9)`) | Average |
| < 200 | Orange (`rgba(249, 115, 22, 0.9)`) | Below average |
| No data | Gray (`rgba(148, 163, 184, 0.9)`) | Statistics unavailable |

All markers have a white 2px stroke border.

## Interactions

### Hover
Shows tooltip with:
- School name (bold)
- Meritvärde (if available)

### Click
Triggers the `onSchoolClick` callback with the `school_unit_code`, which loads detailed school statistics in the sidebar.

## Data Loading

Schools are loaded on demand when a DeSO area is selected. The sidebar fetches:

```
GET /api/deso/{code}/schools
```

The response is tier-gated (see [DeSO Schools API](/api/deso-schools)). The map component receives the school array via `setSchoolMarkers()` on the imperative handle.

## Related

- [Map Rendering](/frontend/map-rendering)
- [DeSO Schools API](/api/deso-schools)
- [School Quality Indicators](/indicators/school-quality)
