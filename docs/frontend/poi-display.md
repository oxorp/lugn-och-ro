# POI Display

> Point of interest markers with category-specific icons and colors.

## When POIs Appear

POIs appear in two ways:

1. **Pin-based** — When a pin is dropped, the location API returns POIs within **3 km**. These are rendered via `setPoiMarkers()`.
2. **Viewport-based** — The `usePoiLayer` hook can fetch POIs for the visible map area (with clustering at lower zooms).

## Marker Style

POIs use Lucide SVG icons rendered as OpenLayers `Icon` styles, matching each category's configured icon and color:

```typescript
if (iconName && hasIcon(iconName)) {
    const dataUrl = getPoiMarkerDataUrl(iconName, color, iconSize);
    // Render as pin-shaped marker with icon
} else {
    // Fallback: colored circle (radius 5)
}
```

### Icon Size by Zoom

| Zoom | Icon Size |
|---|---|
| < 14 | 20 px |
| 14 | 24 px |
| 15+ | 28 px |

### Icon Generation

**File**: `resources/js/lib/poi-icons.ts`

The `getPoiMarkerDataUrl()` function generates SVG data URLs for map markers. Each marker is a colored pin shape with a white Lucide icon centered inside.

### Category Colors and Icons

Category metadata (name, color, icon, signal) comes from the `poi_categories` database table and is included in the location API response. Examples:

| Category | Signal | Color | Icon |
|---|---|---|---|
| grocery | positive | green | shopping-cart |
| healthcare | positive | blue | heart-pulse |
| restaurant | positive | orange | utensils |
| gambling | negative | red | dice |
| pawn_shop | negative | red | banknote |
| park | positive | green | tree-pine |
| public_transport_stop | positive | blue | bus |

## Sidebar Display

In the sidebar, POIs are summarized as category counts (not individual listings):

```
Nearby Points of Interest
● Grocery           3
● Healthcare        2
● Restaurant        5
● Public transport  8
```

Each row shows the category color dot, category name, and count.

## Interactions

### Hover Tooltip

- **Single POI**: Name and category label
- **Cluster** (from `usePoiLayer`): Count with positive/negative/neutral breakdown

### Click

No dedicated click handler for pin-based POI markers.

## Related

- [POIs API](/api/pois)
- [POI Indicators](/indicators/poi)
- [Location Lookup API](/api/location-lookup)
- [Admin Dashboard](/frontend/admin-dashboard) — POI category management
