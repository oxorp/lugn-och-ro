# TASK: Map Visual Polish — Country Mask, Opacity Tuning & Edge Rendering Fix

## Context

The map works functionally but has three visual problems that hurt the product feel:

1. **No country masking** — Denmark, Norway, Finland, Germany, Poland are all visible with full OSM detail. At low zoom this is visual noise; at medium zoom (like the screenshot at z14.7 near Helsingborg) the Danish coast across Öresund is fully rendered with roads, buildings, and labels. The user's eye should be drawn to Sweden, not Helsingør.

2. **Opacity too high** — The H3 hexagon/DeSO polygon fills are opaque enough that they wash out the underlying basemap. Street names, building footprints, and road hierarchy are barely readable through the score coloring. The map needs to be a *tinted* basemap, not a colored blanket over a map.

3. **Edge rendering bug** — H3 hexagons at the edges of the viewport (or at the edges of Sweden's boundary) are not being rendered. There's a visible "bald patch" where hexagons should exist but don't. This is likely a viewport loading issue where hexes that partially overlap the visible area are being excluded, or coastal/border DeSOs aren't getting H3 cells assigned.

---

## Problem 1: Country Masking

### What It Should Look Like

Sweden should be the focus. Everything outside Sweden's border should be visually suppressed — either:
- **Option A: Gray mask** — Non-Sweden areas covered with a semi-transparent gray/white overlay. Foreign countries are visible but muted. Roads and labels still slightly readable (for geographic context) but clearly "not our territory."
- **Option B: Full mask** — Non-Sweden areas completely white/light gray, no basemap detail at all. Clean and dramatic but loses geographic context.
- **Option C: Desaturated basemap** — Sweden gets the normal colorful basemap, foreign areas get a desaturated/grayscale version. Most sophisticated look.

**Go with Option A** — it's the best balance. The user can still see that Denmark is across the water (geographic context) but it's clearly dimmed.

### Implementation: Inverted Polygon Overlay

The standard technique is a "world polygon with a Sweden-shaped hole":

1. Create a GeoJSON polygon that covers the entire world (a huge rectangle)
2. Cut out Sweden's boundary as a hole in that polygon
3. Render this as a vector layer with semi-transparent white fill
4. Position it ABOVE the basemap but BELOW the score hexagons

```typescript
// The "mask" polygon: covers the whole world, with Sweden cut out
const worldWithSwedishHole = {
    type: 'Feature',
    geometry: {
        type: 'Polygon',
        coordinates: [
            // Outer ring: the whole world (counterclockwise)
            [[-180, -90], [180, -90], [180, 90], [-180, 90], [-180, -90]],
            // Inner ring: Sweden's border (clockwise = hole)
            swedenBorderCoordinates  // From Sweden boundary GeoJSON
        ]
    }
};
```

**Style:**
```typescript
const maskStyle = new Style({
    fill: new Fill({
        color: 'rgba(245, 245, 245, 0.75)',  // Light gray, 75% opacity
    }),
    // No stroke — we don't want a visible border on the mask itself
});
```

### Sweden Boundary Source

We need a simplified Sweden border polygon. Options:
- Extract from our DeSO data: `SELECT ST_Union(geom) FROM deso_areas` gives Sweden's shape as the union of all DeSOs. **Problem:** this is a complex multipolygon with thousands of vertices (every DeSO boundary). Too heavy for a mask overlay.
- Better: Download a simplified Sweden country boundary from Natural Earth (naturalearthdata.com) or OSM. Simplify to ~500-1000 vertices. This is a one-time static asset.
- Simplest: Use `ST_Simplify(ST_Union(geom), 0.01)` on the DeSO union to reduce vertex count to manageable levels. Store as a static GeoJSON file served by the backend.

**Create an API endpoint:**
```php
Route::get('/api/sweden-boundary', [MapController::class, 'swedenBoundary']);
```

That returns the simplified Sweden border as GeoJSON. Cache aggressively (this never changes). Or even better: generate it once as a static file at build time and serve from `/data/sweden-boundary.geojson`.

### Layer Order

After adding the mask, the layer stack should be:

```
1. OSM base tiles                         (bottom — full detail everywhere)
2. Country mask (white overlay with hole)  (dims everything outside Sweden)
3. H3 hexagons / DeSO polygons            (score coloring, inside Sweden only)
4. Sweden border stroke                    (thin line showing country outline)
5. Selected area highlight                 (thicker outline on clicked DeSO/hex)
6. POI markers                            (schools, transit, etc.)
7. Tooltips / popups                       (top)
```

### Sweden Border Line

In addition to the mask, add a subtle stroke along Sweden's border:

```typescript
const borderStyle = new Style({
    stroke: new Stroke({
        color: 'rgba(100, 116, 139, 0.5)',  // Slate-400 at 50%
        width: 1.5,
        lineDash: [8, 4],  // Subtle dashed line
    }),
    fill: new Fill({ color: 'rgba(0, 0, 0, 0)' }),  // Transparent fill
});
```

This gives a clean "here is Sweden" edge without being heavy-handed.

### Water Masking (Optional Enhancement)

The Öresund strait, Baltic Sea, and Norwegian border lakes are part of the mask polygon's hole. This means water areas inside the Sweden boundary won't be masked. That's fine — water should look like water. But if you want even cleaner separation between Sweden and Denmark, you could:
- Use a Sweden boundary that includes maritime borders (territorial waters)
- Or just accept that the sea between Helsingborg and Helsingør is unmasked — it's water, not a foreign country

---

## Problem 2: Opacity & Contrast Tuning

### Current Problem

The score-colored polygons are too opaque. They cover the basemap so completely that:
- Street names are barely visible
- Road hierarchy (motorway vs local road) is lost
- Building footprints are invisible
- The map feels like a colored blob rather than an annotated map

### The Goal

The user should see a **tinted map** — the basemap is clearly readable with all its detail, but there's a visible color wash showing the score. Think of it like looking at a map through tinted sunglasses. The score color is unmistakable but the map is still a map.

### Opacity Values

The fix is straightforward — reduce fill opacity on the hex/DeSO polygons:

```typescript
// CURRENT (too opaque):
fill: new Fill({ color: `rgba(${r}, ${g}, ${b}, 0.6)` })

// TARGET:
fill: new Fill({ color: `rgba(${r}, ${g}, ${b}, 0.35)` })
```

**0.35 is the sweet spot.** At 0.35:
- Score colors are clearly distinguishable (green vs yellow vs purple)
- Street names are readable through the tint
- Road hierarchy (motorway = thick line) is visible
- Building footprints show through in high-zoom views
- The overall map still obviously communicates "this area is green/good, that area is yellow/mixed"

### Zoom-Dependent Opacity

At different zoom levels, different opacity feels right:

| Zoom | Polygon Opacity | Rationale |
|---|---|---|
| 5-8 | 0.45 | Zoomed out, showing all of Sweden. Stronger color needed because individual hexes are tiny. Basemap detail doesn't matter at this scale. |
| 9-11 | 0.40 | Regional view. Color still needs to dominate but kommun names should be readable. |
| 12-13 | 0.35 | Kommun/neighborhood view. Street names becoming relevant. |
| 14-16 | 0.30 | Street level. User is reading the map. Color is context, not the focus. |
| 17+ | 0.25 | Deep zoom. User is looking at specific buildings/addresses. Lightest tint. |

```typescript
function getScoreOpacity(zoom: number): number {
    if (zoom >= 17) return 0.25;
    if (zoom >= 14) return 0.30;
    if (zoom >= 12) return 0.35;
    if (zoom >= 9) return 0.40;
    return 0.45;
}
```

Update the style function to recalculate opacity on zoom change. OpenLayers styles can use a `resolution`-based function:

```typescript
const hexLayer = new VectorLayer({
    source: hexSource,
    style: (feature, resolution) => {
        const zoom = map.getView().getZoomForResolution(resolution);
        const score = feature.get('score');
        const [r, g, b] = scoreToRgb(score);
        const opacity = getScoreOpacity(zoom);
        
        return new Style({
            fill: new Fill({ color: `rgba(${r}, ${g}, ${b}, ${opacity})` }),
            stroke: new Stroke({
                color: `rgba(${r}, ${g}, ${b}, ${opacity + 0.15})`,  // Border slightly more visible
                width: zoom >= 14 ? 1 : 0.5,
            }),
        });
    },
});
```

### Border Opacity

The hex/DeSO polygon borders should also be tuned. Currently they might be too prominent, adding visual clutter. At high zoom where hexes are large, a thin border is fine. At low zoom where hexes are tiny, borders should nearly vanish or the map becomes a grid mess.

```typescript
function getStrokeConfig(zoom: number, r: number, g: number, b: number, fillOpacity: number) {
    if (zoom >= 14) {
        // Street zoom: thin visible border
        return new Stroke({
            color: `rgba(${r}, ${g}, ${b}, ${fillOpacity + 0.2})`,
            width: 1,
        });
    } else if (zoom >= 10) {
        // Regional: very thin border
        return new Stroke({
            color: `rgba(${r}, ${g}, ${b}, ${fillOpacity + 0.1})`,
            width: 0.5,
        });
    } else {
        // Country: no individual borders (too many hexes, becomes noise)
        return undefined;  // No stroke
    }
}
```

### Selected Area Highlighting

When a DeSO/hex is selected, it needs to stand out from the reduced opacity. The selected area should:
- Have **higher fill opacity** (0.5) so it's clearly distinguished
- Have a **prominent border** (2px, dark)
- Optionally pulse or have a subtle animation

```typescript
const selectedStyle = new Style({
    fill: new Fill({ color: `rgba(${r}, ${g}, ${b}, 0.5)` }),
    stroke: new Stroke({
        color: '#1e293b',  // Slate-800
        width: 2.5,
    }),
});
```

### Basemap Style Consideration

If reducing polygon opacity isn't enough to make the basemap readable, consider switching to a **lighter/cleaner basemap style**. Options:

- **CartoDB Positron** — very light, minimal color, perfect for overlay data: `https://cartodb-basemaps-a.global.ssl.fastly.net/light_all/{z}/{x}/{y}.png`
- **Stamen Toner Lite** — black/white/gray, extremely clean
- **Custom OSM style** — use Mapbox Studio or similar to create a desaturated OSM style

CartoDB Positron is the go-to for data visualization maps. It's designed specifically for having colored overlays on top. The standard OSM Carto style has too much visual weight (green parks, blue water, yellow roads) that fights with our score colors.

**Recommendation:** Offer a basemap toggle in the map controls:
- "Detailed" — standard OSM (current, for when user wants to read the map)
- "Clean" — CartoDB Positron (for when user wants to see score patterns)
- "Satellite" — Esri or Bing aerial (for property inspection)

Default to "Clean" for the best data visualization experience.

---

## Problem 3: Edge Rendering Bug

### Symptoms

H3 hexagons at the outer edges of the viewport are not rendered. There are visible "bald patches" — areas where hexes should exist (the DeSO polygon is there, the basemap is there) but no hex color appears.

### Likely Causes

**Cause A: Viewport clipping during hex generation**

If H3 cells are generated only for hexes whose CENTER falls within the viewport, then hexes at the edge (whose center is just outside the viewport but whose polygon partially overlaps) won't be included. Fix: expand the query bounds by one hex ring (~1km buffer) when loading data.

```typescript
// When computing bbox for API request:
const extent = view.calculateExtent(map.getSize());
const buffer = 0.02;  // ~2km in degrees at Swedish latitudes
const paddedExtent = [
    extent[0] - buffer,
    extent[1] - buffer,
    extent[2] + buffer,
    extent[3] + buffer,
];
```

**Cause B: Coastal/border DeSO → H3 mapping gaps**

When DeSO polygons are mapped to H3 cells, coastal DeSOs with irregular shapes may have gaps. An H3 cell at resolution 8 is ~0.74 km² — if a thin coastal DeSO strip is narrower than a hex, no hex center falls inside it, and it gets zero cells assigned.

Fix: When building the DeSO→H3 mapping, use `h3.polygonToCells()` which includes all cells that INTERSECT the polygon (not just cells whose centers are inside). Or add a buffer to the polygon before computing cells.

**Cause C: Water hexes excluded**

If the pipeline excludes hexes that fall mostly over water (reasonable for scoring), coastal areas might have visible gaps between the last scored hex and the water. These hexes should either:
- Be included with the neighboring DeSO's score (inherit from nearest land hex)
- Be rendered with a neutral/transparent fill so the basemap water shows through
- Have their polygon extended to cover the visual gap

**Cause D: Sweden's outer boundary DeSOs**

The northernmost, southernmost, and island DeSOs (Gotland, Öland) might have H3 coverage issues if the hex grid doesn't perfectly align with Sweden's boundaries.

### Diagnostic Steps

1. Zoom to a visibly affected area (coastal edge, Norway border, near Denmark)
2. Open browser devtools → Network tab
3. Check the hex data API response — are hexes missing for that area, or are they present but not rendering?
4. If missing from API: the backend hex assignment is the issue
5. If present but not rendering: the frontend viewport culling is the issue

### Fix Strategy

**For viewport clipping (Cause A):**
```typescript
// In the hex data loading function:
const bufferDeg = 0.03;  // Generous buffer
const [minX, minY, maxX, maxY] = transformExtent(
    view.calculateExtent(map.getSize()),
    'EPSG:3857', 'EPSG:4326'
);

const paddedBbox = `${minX - bufferDeg},${minY - bufferDeg},${maxX + bufferDeg},${maxY + bufferDeg}`;
```

**For DeSO→H3 mapping gaps (Cause B):**
Add a "fill gaps" step after hex assignment:
```sql
-- Find DeSOs that have zero H3 cells assigned
SELECT deso_code FROM deso_areas da
WHERE NOT EXISTS (
    SELECT 1 FROM deso_h3_mapping dhm WHERE dhm.deso_code = da.deso_code
);

-- For these DeSOs, assign the nearest H3 cell or the cell containing the DeSO centroid
```

**For water/coastal gaps (Cause C):**
Render a "filler" layer behind the hex layer that covers all DeSO polygons with a very light neutral fill. This ensures no DeSO area is ever visually blank:

```typescript
const desoFillLayer = new VectorLayer({
    source: desoPolygonSource,
    style: new Style({
        fill: new Fill({ color: 'rgba(200, 200, 200, 0.1)' }),  // Nearly invisible
    }),
    zIndex: 2,  // Above mask, below hex layer
});
```

---

## Step-by-Step Implementation Order

### Phase 1: Opacity Fix (Quick Win)

1. Reduce hex fill opacity to 0.35
2. Add zoom-dependent opacity function
3. Tune border stroke (thinner at low zoom, remove at very low zoom)
4. Test at multiple zoom levels: z6 (all Sweden), z10 (Stockholm region), z14 (neighborhood)
5. Verify street names and road hierarchy are readable at z14+

### Phase 2: Country Mask

1. Generate simplified Sweden boundary GeoJSON
   - Either from `ST_Simplify(ST_Union(deso_areas.geom), 0.01)` 
   - Or download from Natural Earth / OSM boundaries
2. Create mask endpoint or static file
3. Add mask layer to OpenLayers (world polygon with Sweden hole)
4. Add Sweden border stroke layer
5. Verify: at z6, Denmark/Norway/Finland should be dimmed
6. Verify: at z14 near Helsingborg, Danish coast is clearly suppressed
7. Verify: mask doesn't cover any Swedish territory (check islands: Gotland, Öland, Vinga)

### Phase 3: Edge Rendering Fix

1. Diagnose: is it viewport clipping or data gap?
2. Add viewport buffer (0.03° padding) to hex data queries
3. Check DeSO→H3 mapping for gaps (coastal DeSOs with 0 hexes)
4. Add filler DeSO polygon layer if needed
5. Test edges: Haparanda (Finland border), Strömstad (Norway border), Helsingborg coast, Gotland edges, Trelleborg south coast

### Phase 4: Basemap Options (Enhancement)

1. Add CartoDB Positron as a basemap option
2. Add basemap toggle to map controls (Detailed / Clean / Satellite)
3. Default to "Clean" (Positron)
4. Test score color readability on each basemap

---

## Verification Checklist

### Opacity
- [ ] At z6 (all Sweden): score colors clearly visible, regional names readable
- [ ] At z10 (Stockholm region): individual hexes distinguishable, kommun names readable
- [ ] At z14 (neighborhood): street names readable through color tint
- [ ] At z17 (building level): individual buildings visible, very light tint
- [ ] Selected hex/DeSO clearly stands out from neighbors (higher opacity + border)
- [ ] Color gradient still communicates clearly: green areas vs purple areas vs yellow

### Country Mask
- [ ] Denmark dimmed when viewing Öresund region
- [ ] Norway dimmed when viewing border areas (Strömstad, Charlottenberg)
- [ ] Finland dimmed when viewing Haparanda/Tornio area
- [ ] Swedish islands (Gotland, Öland, Ven) NOT masked — fully visible
- [ ] Lakes inside Sweden NOT masked (Vänern, Vättern, Mälaren visible as water)
- [ ] At z5: clear visual "this is Sweden" shape against dimmed neighbors
- [ ] Mask doesn't cause performance issues (single polygon, not complex)

### Edge Rendering
- [ ] No bald patches at viewport edges when panning
- [ ] Coastal DeSOs (Gothenburg archipelago, Stockholm archipelago) have hex coverage
- [ ] Northern edge (Karesuando) has hex coverage
- [ ] Southern edge (Trelleborg/Smygehuk) has hex coverage
- [ ] Gotland fully covered (no gaps at island edges)
- [ ] Öland fully covered

### Overall
- [ ] Map feels like a professional data visualization product, not a colored blob
- [ ] A first-time user can immediately understand "green = good, purple = bad"
- [ ] The map is still useful AS a map — you can orient yourself, find streets, identify landmarks

---

## Reference: What Good Looks Like

Look at these products for inspiration:
- **NeighborhoodScout** (neighborhoodscout.com) — colored overlay on clean basemap, high readability
- **Mapbox Choropleth examples** — proper opacity handling
- **Valkompassen/SVT Datajournalistik** — Swedish election maps with good basemap integration
- **Booli.se heatmap** — colored overlay that doesn't kill the basemap

The common pattern: **light basemap + 30-40% opacity colored fill + thin borders + clean mask outside area of interest.**

---

## Notes for the Agent

### Don't Over-Engineer the Mask

The Sweden boundary polygon does NOT need to be pixel-perfect. A simplified boundary with ~500-1000 vertices is plenty. The mask is at 75% opacity, so small gaps (<100m) along the border are invisible. Use `ST_Simplify` aggressively.

### The Gotland/Öland Test

Sweden has major inhabited islands. The mask polygon MUST have holes for these (or more precisely: the Sweden boundary must include them as part of the territory). If using `ST_Union` of all DeSOs, this happens automatically since DeSOs cover the islands. If using an external boundary, verify island inclusion.

### CartoDB Positron Tiles

```
https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png
```

Free for most uses. Attribution required: `© OpenStreetMap contributors, © CARTO`. Check their terms for commercial use — may need a CARTO account for production traffic.

Alternative clean basemaps:
- Stadia Alidade Smooth: `https://tiles.stadiamaps.com/tiles/alidade_smooth/{z}/{x}/{y}.png`
- Thunderforest Transport: cleaner than standard OSM, good road hierarchy

### Performance: The Mask Polygon

A world-sized polygon with a Sweden-shaped hole is ONE feature. OpenLayers renders it as a single fill operation. No performance concern at all — it's lighter than rendering 10 hexagons. Don't over-think this.

### What NOT to Do

- Don't try to style foreign countries differently in the OSM tile layer — you can't control tile rendering
- Don't use a raster mask (PNG overlay) — it won't align properly at all zoom levels
- Don't set hex opacity to 0.1 trying to be subtle — the score colors become invisible and the product loses its point
- Don't hardcode Sweden's boundary coordinates in TypeScript — serve from backend, generate from data
- Don't forget to handle the DeSO polygon view mode (not just H3) — opacity changes apply to both