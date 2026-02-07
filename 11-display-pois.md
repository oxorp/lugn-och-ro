# TASK: POI Map Display — Icons, Smart Visibility, Clustering & Controls

## Context

POIs are currently only shown as markers for the selected DeSO's schools. This is too restrictive — when a user is exploring an area, they want to see what's around: the paper mill 3 km north, the nature reserve to the south, the schools dotting the kommun. This task builds a proper POI layer system with distinct icons per category, smart zoom-dependent visibility, clustering at low zoom, and user-controllable toggles.

**The key tension:** Showing all 49 POI types across all of Sweden simultaneously would be visual chaos (~100,000+ points). We need a system that reveals the right POIs at the right zoom level, in the right area, with the right visual weight.

---

## Goals

1. Distinct icons per POI category (not colored circles — actual recognizable icons)
2. Smart visibility: POIs appear based on zoom level + viewport, not just selected DeSO
3. Clustering at low zoom levels, individual markers at high zoom
4. Map controls panel to toggle POI categories on/off
5. Admin-configurable parameters for zoom thresholds and clustering
6. Performance: smooth at 60fps even with thousands of visible POIs

---

## Step 1: POI Display Strategy

### 1.1 When Should POIs Be Visible?

**Option A (current):** Only in selected DeSO → too restrictive, user can't see context
**Option B:** In the entire kommun of selected area → better but still tied to selection
**Option C:** Always visible based on zoom level → best for exploration

**Go with Option C, enhanced.** POIs are viewport-based, not selection-based. The map loads POIs for whatever area is on screen, filtered by zoom level. Selection of a DeSO still highlights that area and populates the sidebar, but POIs exist independently.

### 1.2 Zoom-Based Visibility Tiers

Not all POIs should appear at the same zoom level. A nuclear power plant matters at country scale. A recycling station only matters when you're zoomed into a neighborhood.

| Zoom Level | What's Visible | Approximate Scale |
|---|---|---|
| 5-7 | Nothing (just the score-colored map) | All of Sweden / regional |
| 8-9 | **Tier 1: Major landmarks** — nuclear plants, airports, large industrial zones, prisons | County level |
| 10-11 | **Tier 2: Significant facilities** — paper mills, refineries, wastewater plants, landfills, international schools, hospitals, nature reserves | Multi-kommun |
| 12-13 | **Tier 3: Local infrastructure** — quarries, shooting ranges, wind farms, high-voltage lines, marinas, large parks, libraries, rail yards | Kommun level |
| 14-15 | **Tier 4: Neighborhood detail** — schools (all), gyms, cafés, gambling venues, pawn shops, homeless shelters, recycling stations, bus depots | DeSO / street level |
| 16+ | **Tier 5: Everything** — individual fast food restaurants, phone towers, parking structures, individual shops | Block level |

### 1.3 POI Tier Assignment Table

```php
// In a config file or database table — admin can tune these

return [
    // Tier 1: Zoom 8+ (major landmarks)
    'nuclear_plant'         => ['tier' => 1, 'icon' => 'radiation', 'color' => '#dc2626', 'sentiment' => 'negative'],
    'airport_major'         => ['tier' => 1, 'icon' => 'plane', 'color' => '#6b7280', 'sentiment' => 'negative'],
    'prison'                => ['tier' => 1, 'icon' => 'lock', 'color' => '#dc2626', 'sentiment' => 'negative'],
    'large_industrial_zone' => ['tier' => 1, 'icon' => 'factory', 'color' => '#f97316', 'sentiment' => 'negative'],

    // Tier 2: Zoom 10+ (significant facilities)
    'paper_mill'            => ['tier' => 2, 'icon' => 'factory', 'color' => '#f97316', 'sentiment' => 'negative'],
    'oil_refinery'          => ['tier' => 2, 'icon' => 'flame', 'color' => '#dc2626', 'sentiment' => 'negative'],
    'chemical_plant'        => ['tier' => 2, 'icon' => 'flask-conical', 'color' => '#dc2626', 'sentiment' => 'negative'],
    'wastewater_plant'      => ['tier' => 2, 'icon' => 'droplets', 'color' => '#f97316', 'sentiment' => 'negative'],
    'waste_incinerator'     => ['tier' => 2, 'icon' => 'flame', 'color' => '#f97316', 'sentiment' => 'negative'],
    'landfill'              => ['tier' => 2, 'icon' => 'trash-2', 'color' => '#f97316', 'sentiment' => 'negative'],
    'international_school'  => ['tier' => 2, 'icon' => 'globe', 'color' => '#16a34a', 'sentiment' => 'positive'],
    'nature_reserve'        => ['tier' => 2, 'icon' => 'trees', 'color' => '#16a34a', 'sentiment' => 'positive'],
    'hospital'              => ['tier' => 2, 'icon' => 'cross', 'color' => '#16a34a', 'sentiment' => 'positive'],

    // Tier 3: Zoom 12+ (local infrastructure)
    'quarry'                => ['tier' => 3, 'icon' => 'mountain', 'color' => '#f97316', 'sentiment' => 'negative'],
    'shooting_range'        => ['tier' => 3, 'icon' => 'crosshair', 'color' => '#f97316', 'sentiment' => 'negative'],
    'wind_turbine'          => ['tier' => 3, 'icon' => 'wind', 'color' => '#eab308', 'sentiment' => 'negative'],
    'rail_yard'             => ['tier' => 3, 'icon' => 'train-track', 'color' => '#f97316', 'sentiment' => 'negative'],
    'contaminated_land'     => ['tier' => 3, 'icon' => 'triangle-alert', 'color' => '#dc2626', 'sentiment' => 'negative'],
    'school_grundskola'     => ['tier' => 3, 'icon' => 'graduation-cap', 'color' => '#2563eb', 'sentiment' => 'positive'],
    'marina'                => ['tier' => 3, 'icon' => 'sailboat', 'color' => '#16a34a', 'sentiment' => 'positive'],
    'large_park'            => ['tier' => 3, 'icon' => 'tree-pine', 'color' => '#16a34a', 'sentiment' => 'positive'],
    'library'               => ['tier' => 3, 'icon' => 'book-open', 'color' => '#16a34a', 'sentiment' => 'positive'],
    'swimming_facility'     => ['tier' => 3, 'icon' => 'waves', 'color' => '#16a34a', 'sentiment' => 'positive'],

    // Tier 4: Zoom 14+ (neighborhood detail)
    'school_other'          => ['tier' => 4, 'icon' => 'school', 'color' => '#2563eb', 'sentiment' => 'positive'],
    'gym'                   => ['tier' => 4, 'icon' => 'dumbbell', 'color' => '#16a34a', 'sentiment' => 'positive'],
    'specialty_cafe'        => ['tier' => 4, 'icon' => 'coffee', 'color' => '#16a34a', 'sentiment' => 'positive'],
    'premium_grocery'       => ['tier' => 4, 'icon' => 'shopping-cart', 'color' => '#16a34a', 'sentiment' => 'positive'],
    'pharmacy'              => ['tier' => 4, 'icon' => 'pill', 'color' => '#16a34a', 'sentiment' => 'positive'],
    'gambling_venue'        => ['tier' => 4, 'icon' => 'dice-3', 'color' => '#dc2626', 'sentiment' => 'negative'],
    'pawn_shop'             => ['tier' => 4, 'icon' => 'badge-dollar-sign', 'color' => '#dc2626', 'sentiment' => 'negative'],
    'homeless_shelter'      => ['tier' => 4, 'icon' => 'bed', 'color' => '#f97316', 'sentiment' => 'negative'],
    'mosque'                => ['tier' => 4, 'icon' => 'landmark', 'color' => '#6b7280', 'sentiment' => 'neutral'],
    'methadone_clinic'      => ['tier' => 4, 'icon' => 'syringe', 'color' => '#f97316', 'sentiment' => 'negative'],
    'nightclub'             => ['tier' => 4, 'icon' => 'music', 'color' => '#f97316', 'sentiment' => 'negative'],
    'medical_center'        => ['tier' => 4, 'icon' => 'stethoscope', 'color' => '#16a34a', 'sentiment' => 'positive'],
    'bookshop'              => ['tier' => 4, 'icon' => 'book', 'color' => '#16a34a', 'sentiment' => 'positive'],

    // Tier 5: Zoom 16+ (everything)
    'fast_food'             => ['tier' => 5, 'icon' => 'utensils', 'color' => '#6b7280', 'sentiment' => 'negative'],
    'phone_tower'           => ['tier' => 5, 'icon' => 'signal', 'color' => '#6b7280', 'sentiment' => 'negative'],
    'recycling_station'     => ['tier' => 5, 'icon' => 'recycle', 'color' => '#6b7280', 'sentiment' => 'negative'],
    'parking_structure'     => ['tier' => 5, 'icon' => 'car', 'color' => '#6b7280', 'sentiment' => 'negative'],
    'bus_depot'             => ['tier' => 5, 'icon' => 'bus', 'color' => '#6b7280', 'sentiment' => 'negative'],
    'sex_shop'              => ['tier' => 5, 'icon' => 'store', 'color' => '#dc2626', 'sentiment' => 'negative'],
    'systembolaget'         => ['tier' => 5, 'icon' => 'wine', 'color' => '#6b7280', 'sentiment' => 'neutral'],
];
```

---

## Step 2: Icon System

### 2.1 Icon Library

Use **Lucide icons** (already in the project via shadcn). Lucide has ~1,400 icons covering all our categories. For the map, we render them as small SVG markers.

### 2.2 Marker Design

Each POI marker on the map is:

```
┌──────┐
│  🏭  │  ← Lucide icon (16×16 at zoom 14+, 12×12 at zoom 10-13)
│      │
└──┬───┘
   │       ← Small pointer/stem
   ▽
```

**Marker structure:**
- Rounded square background (24×24 px at default zoom)
- Background color based on sentiment: green (positive), red/orange (negative), gray (neutral)
- White icon inside
- Small drop shadow for depth
- At lower zoom levels, markers shrink (16×16, then 12×12)

**NOT a colored circle** — actual icons are recognizable at a glance. A factory icon means factory. A graduation cap means school. Users don't need to read tooltips to understand what they're looking at.

### 2.3 OpenLayers Icon Style

```typescript
// lib/poi-styles.ts

import { Style, Icon, Text, Fill, Stroke } from 'ol/style';

interface PoiStyleConfig {
    icon: string;       // Lucide icon name
    color: string;      // Background hex
    sentiment: 'positive' | 'negative' | 'neutral';
}

function createPoiMarkerSvg(config: PoiStyleConfig, size: number): string {
    const bgColor = config.color;
    const iconSvg = getLucideIconPath(config.icon); // Pre-rendered SVG paths

    return `
        <svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size + 6}">
            <defs>
                <filter id="shadow" x="-20%" y="-20%" width="140%" height="140%">
                    <feDropShadow dx="0" dy="1" stdDeviation="1" flood-opacity="0.25"/>
                </filter>
            </defs>
            <rect x="2" y="2" width="${size - 4}" height="${size - 4}"
                  rx="4" fill="${bgColor}" filter="url(#shadow)"/>
            <g transform="translate(${(size - 14) / 2}, ${(size - 14) / 2})"
               fill="none" stroke="white" stroke-width="1.5" stroke-linecap="round">
                ${iconSvg}
            </g>
            <polygon points="${size / 2 - 3},${size - 2} ${size / 2},${size + 4} ${size / 2 + 3},${size - 2}"
                     fill="${bgColor}"/>
        </svg>
    `;
}

function getPoiStyle(poiType: string, zoom: number): Style {
    const config = POI_CONFIG[poiType];
    if (!config) return new Style(); // invisible

    // Size scales with zoom
    const size = zoom >= 14 ? 28 : zoom >= 12 ? 22 : 16;

    const svg = createPoiMarkerSvg(config, size);
    const dataUrl = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);

    return new Style({
        image: new Icon({
            src: dataUrl,
            anchor: [0.5, 1],  // Bottom center
            scale: 1,
        }),
    });
}
```

### 2.4 Pre-Rendered Icon Atlas (Performance)

Generating SVG per marker at runtime is expensive with thousands of POIs. Instead:

1. **Pre-render an icon atlas** — a single sprite sheet PNG with all icon variants (type × size × state)
2. Use OpenLayers `Icon` style with `offset` into the atlas
3. Generate the atlas at build time or app startup

Alternatively, use `ol/style/RegularShape` for simpler markers at low zoom and only switch to full SVG icons at zoom 13+.

```typescript
// At zoom < 13: simple colored dot (fast)
function getSimplePoiStyle(poiType: string): Style {
    const config = POI_CONFIG[poiType];
    return new Style({
        image: new CircleStyle({
            radius: 4,
            fill: new Fill({ color: config.color }),
            stroke: new Stroke({ color: '#ffffff', width: 1 }),
        }),
    });
}

// At zoom >= 13: full icon marker (detailed)
function getDetailedPoiStyle(poiType: string, zoom: number): Style {
    return getPoiStyle(poiType, zoom);  // SVG icon
}
```

---

## Step 3: Clustering

### 3.1 Why Cluster?

At zoom 10, a kommun might have 200 POIs visible. Without clustering, they overlap into an unreadable mess. Clustering groups nearby markers into a single "bubble" showing the count.

### 3.2 OpenLayers Cluster Source

```typescript
import Cluster from 'ol/source/Cluster';
import VectorSource from 'ol/source/Vector';

const poiSource = new VectorSource({ features: [] });

const clusterSource = new Cluster({
    distance: 40,         // Pixel distance for clustering
    minDistance: 20,       // Minimum distance between clusters
    source: poiSource,
});

const clusterLayer = new VectorLayer({
    source: clusterSource,
    style: (feature, resolution) => clusterStyle(feature, resolution),
});
```

### 3.3 Cluster Style

```typescript
function clusterStyle(feature: Feature, resolution: number): Style {
    const features = feature.get('features');
    const count = features.length;
    const zoom = getZoomFromResolution(resolution);

    if (count === 1) {
        // Single POI — show the actual icon
        const poi = features[0];
        const type = poi.get('poi_type');
        return zoom >= 13 ? getDetailedPoiStyle(type, zoom) : getSimplePoiStyle(type);
    }

    // Cluster bubble — show count with dominant sentiment color
    const sentimentCounts = countSentiments(features);
    const dominantColor = getDominantColor(sentimentCounts);
    const radius = Math.min(20, 10 + Math.log2(count) * 3);

    return new Style({
        image: new CircleStyle({
            radius,
            fill: new Fill({ color: dominantColor }),
            stroke: new Stroke({ color: '#ffffff', width: 2 }),
        }),
        text: new Text({
            text: count.toString(),
            fill: new Fill({ color: '#ffffff' }),
            font: `bold ${radius}px Inter, sans-serif`,
        }),
    });
}

function countSentiments(features: Feature[]): { positive: number; negative: number; neutral: number } {
    return features.reduce((acc, f) => {
        const type = f.get('poi_type');
        const sentiment = POI_CONFIG[type]?.sentiment || 'neutral';
        acc[sentiment]++;
        return acc;
    }, { positive: 0, negative: 0, neutral: 0 });
}

function getDominantColor(counts: { positive: number; negative: number; neutral: number }): string {
    if (counts.negative > counts.positive) return '#f97316';    // Orange
    if (counts.positive > counts.negative) return '#16a34a';    // Green
    return '#6b7280';                                           // Gray (mixed/neutral)
}
```

### 3.4 Cluster Behavior by Zoom

| Zoom | Clustering | Marker Style |
|---|---|---|
| 8-11 | Aggressive (distance: 60px) | Clusters only, colored by dominant sentiment |
| 12-13 | Moderate (distance: 40px) | Mix of clusters and individual dots |
| 14-15 | Light (distance: 25px) | Mostly individual icons, small clusters where dense |
| 16+ | Off (distance: 0) | All individual icons, no clustering |

The clustering distance parameter should be zoom-dependent:

```typescript
function getClusterDistance(zoom: number): number {
    if (zoom >= 16) return 0;    // No clustering
    if (zoom >= 14) return 25;
    if (zoom >= 12) return 40;
    return 60;
}
```

Since OpenLayers `Cluster` source doesn't dynamically change distance, we either:
- Recreate the cluster source on zoom change (simple but causes flicker)
- Use multiple cluster layers with visibility ranges (cleaner)
- Use a custom clustering implementation that recalculates on zoom

**Recommended:** Multiple layers with `minZoom`/`maxZoom`:

```typescript
const highZoomLayer = createPoiLayer({ minZoom: 14, clusterDistance: 25 });
const midZoomLayer = createPoiLayer({ minZoom: 10, maxZoom: 14, clusterDistance: 50 });
const lowZoomLayer = createPoiLayer({ minZoom: 8, maxZoom: 10, clusterDistance: 80 });
```

---

## Step 4: Data Loading Strategy

### 4.1 API Endpoint

POIs are loaded by viewport, not by DeSO. The frontend sends the current map bounds:

```php
Route::get('/api/pois', [PoiController::class, 'index']);
```

```php
public function index(Request $request)
{
    $request->validate([
        'bbox' => 'required|string',             // "minLng,minLat,maxLng,maxLat"
        'zoom' => 'required|integer|min:5|max:20',
        'categories' => 'nullable|string',        // "negative,positive" or specific types
    ]);

    [$minLng, $minLat, $maxLng, $maxLat] = explode(',', $request->bbox);
    $zoom = $request->integer('zoom');
    $categories = $request->string('categories');

    // Determine which tiers are visible at this zoom
    $maxTier = $this->zoomToMaxTier($zoom);

    $query = DB::table('pois')
        ->select('id', 'name', 'poi_type', 'category', 'sentiment',
                 'lat', 'lng', 'extra_data')
        ->whereRaw("lng BETWEEN ? AND ?", [$minLng, $maxLng])
        ->whereRaw("lat BETWEEN ? AND ?", [$minLat, $maxLat])
        ->where('display_tier', '<=', $maxTier);

    // Category filter (if user has toggled some off)
    if ($categories) {
        $categoryList = explode(',', $categories);
        $query->whereIn('category', $categoryList);
    }

    // Limit to prevent huge responses at low zoom over wide areas
    $limit = match(true) {
        $zoom >= 14 => 5000,
        $zoom >= 12 => 2000,
        $zoom >= 10 => 500,
        default => 200,
    };

    return response()->json(
        $query->limit($limit)->get()
    );
}

private function zoomToMaxTier(int $zoom): int
{
    return match(true) {
        $zoom >= 16 => 5,
        $zoom >= 14 => 4,
        $zoom >= 12 => 3,
        $zoom >= 10 => 2,
        $zoom >= 8  => 1,
        default => 0,
    };
}
```

### 4.2 Spatial Index

The `pois` table must have a spatial index for fast bbox queries:

```php
Schema::table('pois', function (Blueprint $table) {
    $table->unsignedTinyInteger('display_tier')->default(4)->index();
    $table->string('sentiment', 10)->default('neutral')->index();
    // Ensure lat/lng have a compound index for bbox queries
    $table->index(['lat', 'lng']);
});

// Or better: use PostGIS spatial index
DB::statement("CREATE INDEX pois_geom_bbox_idx ON pois USING GIST (geom)");
```

With PostGIS, the bbox query becomes:

```sql
WHERE ST_Intersects(
    geom,
    ST_MakeEnvelope(:minLng, :minLat, :maxLng, :maxLat, 4326)
)
AND display_tier <= :maxTier
```

### 4.3 Frontend Loading with Debounce

Load POIs on map move/zoom, debounced to avoid spamming the API:

```typescript
// hooks/usePoiLayer.ts

function usePoiLayer(map: Map, enabledCategories: Set<string>) {
    const abortRef = useRef<AbortController | null>(null);

    const loadPois = useMemo(
        () =>
            debounce(async () => {
                // Cancel previous request
                abortRef.current?.abort();
                abortRef.current = new AbortController();

                const view = map.getView();
                const zoom = Math.round(view.getZoom() ?? 0);

                if (zoom < 8) {
                    clearPoiLayer();
                    return;
                }

                const extent = view.calculateExtent(map.getSize());
                const [minLng, minLat, maxLng, maxLat] = transformExtent(
                    extent, 'EPSG:3857', 'EPSG:4326'
                );

                // Pad the bbox slightly to preload markers just outside viewport
                const padLng = (maxLng - minLng) * 0.1;
                const padLat = (maxLat - minLat) * 0.1;

                const params = new URLSearchParams({
                    bbox: `${minLng - padLng},${minLat - padLat},${maxLng + padLng},${maxLat + padLat}`,
                    zoom: zoom.toString(),
                    categories: [...enabledCategories].join(','),
                });

                const response = await fetch(`/api/pois?${params}`, {
                    signal: abortRef.current.signal,
                });

                if (!response.ok) return;
                const pois = await response.json();

                updatePoiFeatures(pois, zoom);
            }, 300),  // 300ms debounce
        [map, enabledCategories]
    );

    useEffect(() => {
        map.on('moveend', loadPois);
        return () => map.un('moveend', loadPois);
    }, [map, loadPois]);
}
```

### 4.4 Caching Strategy

POIs don't change often (monthly updates at most). Cache aggressively:

**Server-side:**
```php
return response()->json($pois)
    ->header('Cache-Control', 'public, max-age=3600');  // 1 hour
```

**Client-side:** Keep a local feature cache keyed by `tileKey(zoom, bbox)`. On pan, check cache first, only fetch for uncached areas. This means panning back to a previously viewed area is instant.

---

## Step 5: Map Controls Panel

### 5.1 POI Toggle Panel

A collapsible panel in the top-left (or bottom-left) of the map that lets users control POI visibility:

```
┌─────────────────────────────────┐
│ 📍 Points of Interest       [▾] │
├─────────────────────────────────┤
│                                 │
│ ⚠️ Nuisances                    │
│  ☑ 🏭 Industrial (12)          │
│  ☑ 💧 Wastewater/Waste (8)     │
│  ☑ ⛏️ Quarries (3)             │
│  ☑ 🔊 Noise sources (5)       │
│  ☑ 🎰 Social (14)              │
│  ☐ 🔌 Infrastructure (22)      │
│                                 │
│ ✅ Amenities                    │
│  ☑ 🎓 Schools (7)              │
│  ☑ 🌲 Parks & Nature (4)       │
│  ☑ 🏥 Healthcare (3)           │
│  ☑ 🛒 Shopping (6)             │
│  ☑ ☕ Cafés & Culture (9)      │
│  ☐ 🏊 Sports & Leisure (5)     │
│                                 │
│ Showing 62 of 98 POIs          │
│ [Reset] [All On] [All Off]     │
└─────────────────────────────────┘
```

### 5.2 Category Groups

POIs are grouped into user-friendly categories (not the 49 individual types):

```typescript
const POI_GROUPS = {
    nuisances: {
        label: 'Nuisances',
        icon: 'alert-triangle',
        subcategories: {
            industrial: {
                label: 'Industrial',
                icon: 'factory',
                types: ['paper_mill', 'oil_refinery', 'chemical_plant', 'large_industrial_zone', 'waste_incinerator'],
            },
            waste_water: {
                label: 'Wastewater & Waste',
                icon: 'droplets',
                types: ['wastewater_plant', 'landfill', 'contaminated_land'],
            },
            quarries_mining: {
                label: 'Quarries & Mining',
                icon: 'mountain',
                types: ['quarry'],
            },
            noise_sources: {
                label: 'Noise Sources',
                icon: 'volume-2',
                types: ['airport_major', 'shooting_range', 'rail_yard', 'wind_turbine'],
            },
            social: {
                label: 'Social',
                icon: 'alert-circle',
                types: ['gambling_venue', 'pawn_shop', 'homeless_shelter', 'methadone_clinic',
                         'sex_shop', 'nightclub', 'prison', 'mosque'],
            },
            infrastructure: {
                label: 'Infrastructure',
                icon: 'zap',
                types: ['phone_tower', 'recycling_station', 'parking_structure', 'bus_depot'],
                defaultOff: true,  // Less interesting, off by default
            },
        },
    },
    amenities: {
        label: 'Amenities',
        icon: 'check-circle',
        subcategories: {
            schools: {
                label: 'Schools',
                icon: 'graduation-cap',
                types: ['school_grundskola', 'school_other', 'international_school'],
            },
            nature: {
                label: 'Parks & Nature',
                icon: 'trees',
                types: ['large_park', 'nature_reserve', 'marina', 'swimming_facility'],
            },
            healthcare: {
                label: 'Healthcare',
                icon: 'heart-pulse',
                types: ['hospital', 'medical_center', 'pharmacy'],
            },
            shopping: {
                label: 'Shopping & Food',
                icon: 'shopping-bag',
                types: ['premium_grocery', 'farmers_market', 'systembolaget'],
            },
            culture: {
                label: 'Cafés & Culture',
                icon: 'coffee',
                types: ['specialty_cafe', 'bookshop', 'library', 'cultural_venue'],
            },
            sports: {
                label: 'Sports & Leisure',
                icon: 'dumbbell',
                types: ['gym', 'swimming_facility'],
                defaultOff: true,  // Less critical, off by default
            },
        },
    },
};
```

### 5.3 Controls Component

```tsx
// components/PoiControls.tsx

function PoiControls({
    enabledCategories,
    onToggle,
    poiCounts,
}: PoiControlsProps) {
    const [isExpanded, setIsExpanded] = useState(false);

    return (
        <div className="absolute top-4 left-4 z-10">
            {/* Collapsed: small button */}
            <Button
                variant="outline"
                size="sm"
                className="bg-white shadow-md"
                onClick={() => setIsExpanded(!isExpanded)}
            >
                <MapPin className="h-4 w-4 mr-1" />
                POI
                <ChevronDown className={cn("h-3 w-3 ml-1 transition-transform",
                    isExpanded && "rotate-180")} />
            </Button>

            {/* Expanded: full panel */}
            {isExpanded && (
                <Card className="mt-2 w-64 shadow-lg">
                    <ScrollArea className="max-h-[60vh]">
                        <CardContent className="p-3 space-y-3">
                            {Object.entries(POI_GROUPS).map(([groupKey, group]) => (
                                <PoiGroup
                                    key={groupKey}
                                    group={group}
                                    enabledCategories={enabledCategories}
                                    onToggle={onToggle}
                                    counts={poiCounts}
                                />
                            ))}

                            <div className="flex gap-2 pt-2 border-t">
                                <Button size="xs" variant="ghost" onClick={onReset}>Reset</Button>
                                <Button size="xs" variant="ghost" onClick={onAllOn}>All On</Button>
                                <Button size="xs" variant="ghost" onClick={onAllOff}>All Off</Button>
                            </div>
                        </CardContent>
                    </ScrollArea>
                </Card>
            )}
        </div>
    );
}
```

### 5.4 Persistence

Save the user's POI toggle preferences to `localStorage` so they persist across sessions:

```typescript
const STORAGE_KEY = 'poi-preferences';

function loadPreferences(): Set<string> {
    const saved = localStorage.getItem(STORAGE_KEY);
    if (saved) return new Set(JSON.parse(saved));
    // Default: all on except infrastructure and sports
    return getDefaultCategories();
}

function savePreferences(categories: Set<string>) {
    localStorage.setItem(STORAGE_KEY, JSON.stringify([...categories]));
}
```

---

## Step 6: Interaction — Tooltips & Click Behavior

### 6.1 Hover Tooltip

Hovering over a POI marker shows a lightweight tooltip:

```
┌──────────────────────────────────┐
│ 🏭 Hallsta Pappersbruk           │
│ Paper & Pulp Mill                │
│ Impact radius: ~5 km             │
└──────────────────────────────────┘
```

For schools (which have quality data):

```
┌──────────────────────────────────┐
│ 🎓 Årstaskolan                   │
│ Grundskola · Kommunal            │
│ Meritvärde: ~240 · Top 25%      │
└──────────────────────────────────┘
```

Use OpenLayers overlay positioned at the feature coordinates:

```typescript
map.on('pointermove', (event) => {
    const feature = map.forEachFeatureAtPixel(event.pixel, (f) => f, {
        layerFilter: (layer) => layer === poiLayer,
    });

    if (feature) {
        const clusteredFeatures = feature.get('features');
        if (clusteredFeatures?.length === 1) {
            showTooltip(clusteredFeatures[0], event.coordinate);
        } else if (clusteredFeatures?.length > 1) {
            showClusterTooltip(clusteredFeatures, event.coordinate);
        }
    } else {
        hideTooltip();
    }
});
```

Cluster tooltip:

```
┌──────────────────────────────────┐
│ 12 Points of Interest            │
│ 4 nuisances · 7 amenities · 1 ∅ │
│ Click to zoom in                 │
└──────────────────────────────────┘
```

### 6.2 Click Behavior

**Single POI click:**
- If it's a school: scroll sidebar to that school's card (existing behavior)
- If it's another POI type: show a small info popup on the map with name, type, and impact radius circle
- The impact radius circle renders as a semi-transparent ring around the POI showing its area of effect

**Cluster click:**
- Zoom in to the cluster's extent so individual markers become visible

```typescript
map.on('click', (event) => {
    const feature = map.forEachFeatureAtPixel(event.pixel, (f) => f, {
        layerFilter: (layer) => layer === poiLayer,
    });

    if (!feature) return;

    const clusteredFeatures = feature.get('features');

    if (clusteredFeatures?.length > 1) {
        // Zoom to cluster extent
        const extent = createEmpty();
        clusteredFeatures.forEach((f: Feature) => extend(extent, f.getGeometry()!.getExtent()));
        map.getView().fit(extent, { padding: [50, 50, 50, 50], maxZoom: 16 });
    } else {
        // Single POI — show info / impact radius
        showPoiDetail(clusteredFeatures[0]);
    }
});
```

### 6.3 Impact Radius Visualization

When a negative POI is clicked, optionally show a semi-transparent circle representing its impact radius:

```typescript
function showImpactRadius(poi: Feature) {
    const type = poi.get('poi_type');
    const radiusKm = POI_CONFIG[type]?.impactRadius;
    if (!radiusKm) return;

    const center = poi.getGeometry().getCoordinates();

    // Create a circle geometry (in meters for EPSG:3857)
    const circle = circular(
        toLonLat(center),
        radiusKm * 1000,  // km to meters
        64                 // segments
    ).transform('EPSG:4326', 'EPSG:3857');

    impactRadiusSource.clear();
    impactRadiusSource.addFeature(new Feature(circle));
}
```

Style the circle as a subtle wash:
```typescript
const impactStyle = new Style({
    fill: new Fill({ color: 'rgba(249, 115, 22, 0.08)' }),   // Very light orange
    stroke: new Stroke({ color: 'rgba(249, 115, 22, 0.3)', width: 1, lineDash: [6, 4] }),
});
```

---

## Step 7: Admin Configuration

### 7.1 POI Display Settings Table

```php
Schema::create('poi_display_settings', function (Blueprint $table) {
    $table->id();
    $table->string('poi_type', 60)->unique();
    $table->unsignedTinyInteger('display_tier')->default(4);    // 1-5
    $table->string('icon', 40);                                  // Lucide icon name
    $table->string('color', 10);                                 // Hex color
    $table->string('sentiment', 10);                             // positive/negative/neutral
    $table->string('category_group', 40);                        // For control panel grouping
    $table->float('impact_radius_km')->nullable();               // For radius circle
    $table->boolean('is_visible')->default(true);                // Master kill switch
    $table->boolean('default_enabled')->default(true);           // On by default for new users?
    $table->string('label_sv')->nullable();                      // Swedish display name
    $table->string('label_en')->nullable();                      // English display name
    $table->timestamps();
});
```

### 7.2 Admin POI Display Page

Add a section to the admin dashboard (or a new page at `/admin/poi-display`) where admin can tune:

- **Display tier** per POI type — change which zoom level a type appears at
- **Icon + color** — swap icons if a better one exists
- **Impact radius** — adjust the radius circle
- **Visibility** — completely hide a POI type from the map
- **Default on/off** — whether it's enabled by default for new users

This is the "easy to control parameters for fine tuning" — no code deploys needed.

### 7.3 API for Settings

```php
Route::get('/api/poi-display-settings', [PoiController::class, 'displaySettings']);
```

Returns the full config to the frontend on page load. Cached for 1 hour. The frontend uses this to build the controls panel and style markers.

```php
public function displaySettings()
{
    $settings = Cache::remember('poi-display-settings', 3600, fn () =>
        PoiDisplaySetting::where('is_visible', true)->get()
    );

    return response()->json($settings);
}
```

---

## Step 8: Performance Considerations

### 8.1 WebGL Rendering

For >1000 markers, OpenLayers' default Canvas renderer gets slow. Switch to `ol/layer/WebGLPoints` for the POI layer:

```typescript
import WebGLPointsLayer from 'ol/layer/WebGLPoints';

const poiLayer = new WebGLPointsLayer({
    source: poiSource,  // NOT clustered source — WebGL does its own optimization
    style: {
        'icon-src': '/poi-atlas.png',          // Sprite atlas
        'icon-size': ['interpolate', ['linear'], ['zoom'], 10, 0.5, 16, 1.0],
        'icon-offset': ['match', ['get', 'poi_type'],
            'school_grundskola', [0, 0],
            'wastewater_plant', [32, 0],
            // ... atlas offsets
            [0, 0]   // default
        ],
    },
});
```

WebGL renders 10,000+ points at 60fps. However, WebGL layers don't support clustering natively, so either:
- Use WebGL at high zoom (14+) where clustering isn't needed
- Use regular VectorLayer + Cluster at low zoom (8-13) where fewer points are visible
- Switch layers based on zoom level

### 8.2 Viewport Loading Budget

Set hard limits per zoom level to prevent the API from returning too many points:

| Zoom | Max POIs Returned | Rationale |
|---|---|---|
| 8-9 | 200 | Only tier 1 — nuclear plants, airports. Should be <50 anyway |
| 10-11 | 500 | Tier 1+2. At county view, maybe 100-200 visible |
| 12-13 | 2,000 | Tier 1-3. Kommun view, could be 500-1000 |
| 14-15 | 5,000 | Tier 1-4. Neighborhood view, densest areas might hit this |
| 16+ | 10,000 | Everything. Block view, usually much less than limit |

If the limit is hit, the API returns the POIs sorted by tier (most important first), so lower-tier POIs get cut first.

### 8.3 Spatial Tiling (Future Optimization)

If performance becomes an issue, implement vector tile serving for POIs:
- Pre-generate MVT (Mapbox Vector Tiles) for POIs using PostGIS `ST_AsMVT`
- Serve via a tile endpoint: `/api/pois/tiles/{z}/{x}/{y}.pbf`
- OpenLayers loads tiles automatically based on viewport

This is overkill for initial implementation but is the correct scaling pattern for 100k+ POIs.

---

## Step 9: Verification

### 9.1 Visual Checklist

- [ ] At zoom 5-7 (all Sweden): no POIs visible, just colored DeSO map
- [ ] At zoom 8-9 (regional): major landmarks appear (airports, nuclear plants, large industrial)
- [ ] At zoom 10-11 (county): paper mills, wastewater plants, nature reserves appear
- [ ] At zoom 12-13 (kommun): schools, quarries, parks, libraries appear
- [ ] At zoom 14-15 (neighborhood): cafés, gyms, gambling venues, pawn shops appear
- [ ] At zoom 16+ (street): everything including recycling stations, phone towers
- [ ] Markers use distinct icons — factory looks different from school looks different from park
- [ ] Marker colors match sentiment: green (positive), orange/red (negative), gray (neutral)
- [ ] Clusters form at low zoom with count badges
- [ ] Cluster color reflects dominant sentiment (mostly negative = orange cluster)
- [ ] Clicking a cluster zooms in to reveal individual markers
- [ ] Hovering a POI shows tooltip with name and type
- [ ] Hovering a cluster shows summary ("12 POIs: 4 nuisances, 7 amenities")
- [ ] POI toggle panel is visible and functional
- [ ] Toggling a category off immediately removes those markers
- [ ] Toggle preferences persist across page reloads
- [ ] "All Off" / "All On" / "Reset" buttons work
- [ ] Panning loads new POIs for the new viewport (with debounce)
- [ ] No flicker or performance drop when panning at zoom 14
- [ ] Clicking a negative POI shows impact radius circle (dashed orange ring)
- [ ] Admin can change display tier / icon / visibility in admin dashboard
- [ ] Changes in admin reflect on the map without code deploy

### 9.2 Performance Checklist

- [ ] < 300ms to load POIs for a typical viewport
- [ ] Smooth 60fps pan/zoom with 500+ visible markers
- [ ] No duplicate API calls (debounce works, abort controller cancels stale requests)
- [ ] API responses cached (1 hour Cache-Control)
- [ ] Client-side caching prevents re-fetching when panning back to previous area
- [ ] Database spatial index used (GIST on geom or compound index on lat/lng)

---

## Notes for the Agent

### Start Simple, Layer Complexity

1. First: get POI markers loading by viewport with simple colored dots (no icons, no clustering)
2. Then: add clustering
3. Then: add icon sprites / SVG markers
4. Then: add the toggle panel
5. Then: add hover tooltips and click behavior
6. Last: WebGL optimization, impact radius circles, admin settings

### OpenLayers Layering Order

Map layers from bottom to top:
1. Base tile layer (OSM / satellite)
2. DeSO polygon layer (colored by score)
3. Impact radius circles (dashed rings — below markers)
4. POI markers layer (above polygons)
5. School markers for selected DeSO (highlight layer — above POIs)
6. Selected DeSO outline (thicker border)
7. Tooltip/popup overlays

### Don't Load All POIs at Once

Even at zoom 16 in central Stockholm, there might be 2000 POIs. That's fine. But at zoom 8 over all of Sweden, there could be 100,000+. The tier system + viewport loading + limits prevent this from being a problem. Trust the tier system.

### The Toggle Panel Should NOT Be Another Sidebar

The POI controls float over the map (top-left, collapsible). They are NOT part of the right sidebar. The sidebar is for DeSO data. The map controls are for map settings. Mixing them creates confusion.

### What NOT to Do

- Don't load all POIs on page load — viewport-based loading only
- Don't show POIs below zoom 8 — the map is too zoomed out for them to be meaningful
- Don't use `ol/Overlay` for markers (DOM-based, slow at scale) — use vector features with styles
- Don't put the toggle panel in the sidebar — it belongs on the map
- Don't make clustering distance a constant — it must vary by zoom
- Don't render SVG icons at zoom 8-12 — use simple circles, save icons for zoom 13+
- Don't forget to abort in-flight requests when the user pans — stale responses cause flicker