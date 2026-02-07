# POI Display

> Point of interest layer with clustering and category controls.

## POI Layer

**Hook**: `resources/js/hooks/use-poi-layer.ts`

POIs are loaded as a separate vector layer with client-side clustering. The hook manages:
- Fetching POIs from `/api/pois` based on viewport and enabled categories
- Clustering nearby POIs at lower zoom levels
- Styling clusters by sentiment (positive/negative/neutral)

## POI Controls

**Component**: `resources/js/components/poi-controls.tsx`

A floating panel (top-left, below search) that lets users toggle POI visibility.

### Group Structure

POI categories are organized into groups:

| Group | Sentiment | Examples |
|---|---|---|
| Nuisances | Negative (orange) | Gambling, pawn shops, fast food, liquor stores |
| Amenities | Positive (green) | Grocery, healthcare, restaurants, fitness |
| Other | Neutral | Transit stops, parks |

### Controls

- **Group toggle**: Expand/collapse category groups
- **Category checkbox**: Toggle individual categories (supports indeterminate state)
- **Quick actions**: "All", "None", "Reset" buttons
- **Counter**: Shows enabled category count and visible POI count

### Category Configuration

Categories are defined in `resources/js/lib/poi-config.ts` with subcategories mapping to backend `poi_categories` slugs.

## Interactions

### Hover (tooltip)
- **Single POI**: Name and type
- **Cluster**: Count with sentiment breakdown (e.g., "3 nuisances, 2 amenities")

### Click
- **Cluster**: Zooms to cluster extent (max zoom 16)
- **Single POI**: Shows impact radius and details

## Related

- [POIs API](/api/pois)
- [POI Indicators](/indicators/poi)
- [Admin Dashboard](/frontend/admin-dashboard) — POI category management
